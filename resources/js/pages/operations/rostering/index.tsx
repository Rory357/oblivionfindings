import HeadingSmall from '@/components/heading-small';
import { KpiCard } from '@/components/recruitment/kpi-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
import { index as rosteringIndex } from '@/routes/operations/rostering';
import {
    destroy as destroyTimeOff,
    store as storeTimeOff,
} from '@/routes/rostering/time_off';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Calendar,
    CalendarOff,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Plus,
    TrendingUp,
    Users,
    XCircle,
} from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Legend,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

const CHART_COLORS = [
    '#3b82f6',
    '#10b981',
    '#f59e0b',
    '#8b5cf6',
    '#ef4444',
    '#06b6d4',
    '#94a3b8',
];

type Staff = { id: number; name: string; email?: string };
type Client = { id: number; first_name: string; last_name: string };
type Site = { id: number; name: string; type?: string | null };
type ShiftLite = {
    id: number;
    client_id: number;
    user_id: number | null;
    shift_series_id?: number | null;
    starts_at: string;
    ends_at: string;
    location: string | null;
    status: string;
    roster_period_id?: number | null;
    published_at?: string | null;
    publish_dirty_at?: string | null;
    shift_type?: string | null;
    service_context: string | null;
    client: string | null;
    staff: string | null;
    tasks_total: number;
    tasks_completed: number;
    incidents_count: number;
    timesheet_status: string | null;
    has_active_replacement?: boolean;
    replacement_status?: string | null;
    replacement_reason?: string | null;
    replacement_requested_by?: string | null;
    replacement_current_staff?: string | null;
    open_position_status?: string | null;
};

type RosterPeriodSummary = {
    id: number;
    site_id: number;
    week_start: string;
    week_end?: string | null;
    version: number;
    status: string;
    shift_count?: number | null;
    published_at: string | null;
    last_validated_at: string | null;
    validation_summary?: {
        can_publish?: boolean;
        blocks?: Array<{ message: string }>;
        warnings?: Array<{ message: string }>;
        shift_count?: number;
    } | null;
    diff_summary?: {
        added: number;
        removed: number;
        changed: number;
        total: number;
    } | null;
};

type ReplacementQueueItem = {
    id: number;
    shift_id: number;
    status: string;
    reason: string;
    requested_at?: string | null;
    starts_at?: string | null;
    ends_at?: string | null;
    client: string | null;
    location: string | null;
    current_staff?: string | null;
    requested_by?: string | null;
    replacement_staff?: string | null;
    open_position_id?: number | null;
    open_position_status?: string | null;
    open_position_claimed_by?: string | null;
    expires_at?: string | null;
};

type RecurringPattern = {
    id: number;
    client: string | null;
    staff: string | null;
    service_context: string | null;
    location: string | null;
    status: string;
    shift_type?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    weekdays: string[];
    starts_time?: string | null;
    ends_time?: string | null;
    occurrences_this_week: number;
    open_occurrences: number;
    active_replacement_count: number;
    next_shift_id?: number | null;
    next_starts_at?: string | null;
};

type CoverageAlert = {
    site_id: number;
    site_name: string;
    rule_id?: number;
    rule_name: string;
    window_label: string;
    starts_at?: string;
    ends_at?: string;
    required_staff: number;
    assigned_staff: number;
    planned_staff?: number;
    missing_staff: number;
    preferred_client_id?: number | null;
    role_shortages?: Array<{
        key: string;
        label?: string | null;
        missing?: number;
    }>;
    planned_role_shortages?: Array<{
        key: string;
        label?: string | null;
        missing?: number;
    }>;
    unfilled_after_open_shifts?: number;
    coverage_state: string;
    planned_coverage_state?: string;
    gap_kind?: string | null;
    recommended_fill_action?: string | null;
    contradictions?: string[];
    open_shift_ids?: number[];
};

type CoverageSiteSummary = {
    site_id: number;
    site_name: string;
    total_windows: number;
    under_covered_windows: number;
    exact_windows: number;
    overstaffed_windows: number;
    largest_missing_staff: number;
    alerts: CoverageAlert[];
};

type Props = {
    canManageAny: boolean;
    canPublishRoster: boolean;
    canAutoScheduleRoster: boolean;
    rosteringFeatures: {
        publish: boolean;
        auto_schedule: boolean;
    };
    weekStart: string; // YYYY-MM-DD
    weekEnd: string; // YYYY-MM-DD (exclusive)
    filters: {
        week: string;
        staff_id: number | null;
        client_id: number | null;
        site_id: number | null;
    };
    staff: Staff[];
    clients: Client[];
    sites: Site[];
    rosterPeriod: RosterPeriodSummary | null;
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
        coverage_gaps?: number;
    };
    shifts: ShiftLite[];
    replacementQueue: ReplacementQueueItem[];
    recurringPatterns: RecurringPattern[];
    coverageSites: CoverageSiteSummary[];
    coverageAlerts: CoverageAlert[];
    recurringCoverageAlignment: {
        rule_drift: Array<{
            site_id: number;
            site_name: string;
            rule_name: string;
            window_label: string;
            starts_at?: string;
            ends_at?: string;
            required_staff: number;
            assigned_staff: number;
            open_shifts: number;
            missing_staff: number;
            issue_type: string;
        }>;
        orphan_series: Array<{
            series_id: number;
            site_id?: number | null;
            site_name: string;
            client_name?: string | null;
        }>;
    };
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
    analytics: {
        dailyCoverage: Array<{
            day: string;
            date: string;
            scheduled: number;
            filled: number;
            open: number;
        }>;
        shiftTypeDistribution: Array<{ type: string; value: number }>;
        historicalTrend: Array<{
            week: string;
            completed: number;
            cancelled: number;
            total: number;
        }>;
        coverageRate: number;
        staffRostered: number;
        onLeaveCount: number;
        complianceExpiring: number;
        complianceExpired: number;
    };
    eligibilityAlerts: {
        counts: {
            eligible: number;
            warnings: number;
            blocked: number;
            overrides: number;
        };
        blocked: Array<{
            id: number;
            starts_at: string;
            staff: string;
            site: string;
            reason: string;
        }>;
        warnings: Array<{
            id: number;
            starts_at: string;
            staff: string;
            site: string;
            reason: string;
        }>;
    };
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
    return d.toLocaleDateString(undefined, {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });
}

function fmtTime(iso: string) {
    const d = new Date(iso);
    return d.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function fmtHour(h: number) {
    return `${String(h).padStart(2, '0')}:00`;
}

function weekdayLabel(code: string) {
    const labels: Record<string, string> = {
        mon: 'Mon',
        tue: 'Tue',
        wed: 'Wed',
        thu: 'Thu',
        fri: 'Fri',
        sat: 'Sat',
        sun: 'Sun',
    };

    return labels[code] ?? code;
}

function shiftTypeLabel(value?: string | null) {
    return (value ?? 'standard').replace(/_/g, ' ');
}

function seriesTimeLabel(startsTime?: string | null, endsTime?: string | null) {
    if (!startsTime || !endsTime) return '';
    const overnight = endsTime <= startsTime;
    return `${startsTime}–${endsTime}${overnight ? ' overnight' : ''}`;
}

function statusBadgeVariant(status: string): any {
    if (status === 'in_progress') return 'default';
    if (status === 'completed') return 'secondary';
    if (status === 'cancelled') return 'destructive';
    if (status === 'draft') return 'outline';
    return 'outline';
}

function replacementBadgeVariant(status?: string | null): any {
    if (status === 'claimed') return 'default';
    if (status === 'approved') return 'secondary';
    if (status === 'cancelled') return 'destructive';
    return 'outline';
}

function coverageRolesForAction(alert: CoverageAlert) {
    return (
        (alert.planned_role_shortages?.length
            ? alert.planned_role_shortages
            : alert.role_shortages) ?? []
    );
}

function gapKindLabel(kind?: string | null) {
    switch (kind) {
        case 'headcount_open':
            return 'Open shift gap';
        case 'headcount_unplanned':
            return 'Unplanned headcount gap';
        case 'role_open':
            return 'Open role gap';
        case 'role_unplanned':
            return 'Unplanned role gap';
        case 'mixed_open':
            return 'Open shift + role gap';
        case 'mixed_unplanned':
            return 'Headcount + role gap';
        case 'overfill_not_allowed':
            return 'Overfill not allowed';
        case 'overfilled_wrong_role_mix':
            return 'Overfilled role imbalance';
        case 'overfill_and_role_imbalance':
            return 'Overfill + role imbalance';
        default:
            return 'Coverage gap';
    }
}

function fillActionLabel(action?: string | null) {
    switch (action) {
        case 'fill_existing_open_shift':
            return 'Fill open shift';
        case 'retag_or_replace_open_shift':
            return 'Retag open shift';
        case 'create_role_specific_shift':
            return 'Create role-specific cover';
        case 'create_recurring_cover':
            return 'Create recurring cover';
        case 'review_existing_supply':
            return 'Review existing supply';
        case 'rebalance_existing_supply':
            return 'Rebalance existing supply';
        default:
            return 'Create cover shift';
    }
}

function shouldOfferCreation(action?: string | null) {
    return !['review_existing_supply', 'rebalance_existing_supply'].includes(
        action ?? '',
    );
}

function rangesOverlap(
    aStartIso: string,
    aEndIso: string,
    bStartIso: string,
    bEndIso: string,
) {
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
        user_id: props.filters.staff_id
            ? String(props.filters.staff_id)
            : 'self',
        starts_at: `${props.weekStart}T09:00`,
        ends_at: `${props.weekStart}T17:00`,
        type: 'leave',
        label: '',
        notes: '',
        return_to: '/operations/rostering',
    });

    const assignForm = useForm({
        user_id: '',
        return_to: '/operations/rostering',
    });

    // Ops dashboard: per-shift quick assignment selections
    const [opsAssignSelection, setOpsAssignSelection] = useState<
        Record<number, string>
    >({});

    const [resolveModal, setResolveModal] = useState<null | {
        kind: 'staff' | 'client';
        a: ShiftLite;
        b: ShiftLite;
        staffId?: number;
        clientId?: number;
    }>(null);

    const [resolveReassignSelection, setResolveReassignSelection] = useState<
        Record<number, string>
    >({});
    const [coverageMode, setCoverageMode] = useState<
        'understaffed' | 'assigned'
    >('understaffed');
    const coverageReturnTo = `/operations/rostering?week=${encodeURIComponent(props.weekStart)}`;
    const buildCoverageCreateHref = (
        alert: CoverageAlert,
        options?: { openShift?: boolean; repeatWeekly?: boolean },
    ) => {
        const params = new URLSearchParams();
        params.set('site_id', String(alert.site_id));
        if (alert.rule_id)
            params.set('coverage_rule_id', String(alert.rule_id));
        if (alert.starts_at) params.set('starts_at', alert.starts_at);
        if (alert.ends_at) params.set('ends_at', alert.ends_at);
        if (alert.preferred_client_id) {
            params.set('client_id', String(alert.preferred_client_id));
        }
        params.set('coverage_rule_name', alert.rule_name);
        params.set('coverage_required_staff', String(alert.required_staff));
        params.set('coverage_missing_staff', String(alert.missing_staff));
        if (coverageRolesForAction(alert).length > 0) {
            params.set(
                'coverage_role_shortages',
                JSON.stringify(coverageRolesForAction(alert)),
            );
        }
        params.set('return_to', coverageReturnTo);
        if (options?.openShift) params.set('open_shift', '1');
        if (options?.repeatWeekly) {
            params.set('repeat_weekly', '1');
            if (alert.starts_at) {
                const repeatEnd = new Date(alert.starts_at);
                repeatEnd.setDate(repeatEnd.getDate() + 28);
                params.set(
                    'repeat_end_date',
                    repeatEnd.toISOString().slice(0, 10),
                );
            }
        }

        return `/operations/shifts/create?${params.toString()}`;
    };

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

    const startDate = useMemo(
        () => new Date(`${props.weekStart}T00:00:00`),
        [props.weekStart],
    );

    const staffById = useMemo(() => {
        const m = new Map<number, Staff>();
        for (const s of props.staff) m.set(s.id, s);
        return m;
    }, [props.staff]);

    const siteCoverageChartData = useMemo(
        () =>
            (props.coverageSites ?? []).slice(0, 6).map((site) => ({
                site: site.site_name,
                under: site.under_covered_windows,
                exact: site.exact_windows,
                over: site.overstaffed_windows,
                risk: site.largest_missing_staff,
            })),
        [props.coverageSites],
    );

    const coverageBalanceData = useMemo(() => {
        const totals = (props.coverageSites ?? []).reduce(
            (acc, site) => {
                acc.under += site.under_covered_windows;
                acc.exact += site.exact_windows;
                acc.over += site.overstaffed_windows;
                return acc;
            },
            { under: 0, exact: 0, over: 0 },
        );

        return [
            { name: 'Under-covered', value: totals.under, color: '#ef4444' },
            { name: 'Exact', value: totals.exact, color: '#10b981' },
            { name: 'Overstaffed', value: totals.over, color: '#f59e0b' },
        ].filter((item) => item.value > 0);
    }, [props.coverageSites]);
    const recurringCoverageDriftCount =
        (props.recurringCoverageAlignment?.rule_drift?.length ?? 0) +
        (props.recurringCoverageAlignment?.orphan_series?.length ?? 0);

    const capacityByUserId = useMemo(() => {
        const m = new Map<
            number,
            { hours: number; warn: 'medium' | 'high' | null }
        >();
        for (const c of props.capacity ?? [])
            m.set(c.user_id, { hours: c.hours, warn: c.warn });
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
                return rangesOverlap(
                    s.starts_at,
                    s.ends_at,
                    shift.starts_at,
                    shift.ends_at,
                );
            });
            if (hasShiftConflict) continue;

            const hasTimeOff = (props.timeOffs ?? []).some((t) => {
                if (t.user_id !== u.id) return false;
                return rangesOverlap(
                    t.starts_at,
                    t.ends_at,
                    shift.starts_at,
                    shift.ends_at,
                );
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
            v.sort(
                (a, b) =>
                    new Date(a.starts_at).getTime() -
                    new Date(b.starts_at).getTime(),
            );
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
            v.sort(
                (a, b) =>
                    new Date(a.starts_at).getTime() -
                    new Date(b.starts_at).getTime(),
            );
            map.set(k, v);
        }
        return map;
    }, [props.shifts]);

    const openShifts = useMemo(
        () => props.shifts.filter((s) => s.user_id === null),
        [props.shifts],
    );

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
                const overlaps =
                    s.getTime() < hourEnd && e.getTime() > hourStart;
                if (overlaps) {
                    const dk = ymd(new Date(hourStart));
                    const h = new Date(hourStart).getHours();
                    const dayCell = grid[dk];
                    if (dayCell) {
                        if (sh.user_id === null) {
                            dayCell.open[h] = (dayCell.open[h] ?? 0) + 1;
                        } else {
                            dayCell.assigned[h] =
                                (dayCell.assigned[h] ?? 0) + 1;
                        }
                    }
                }
                cur = new Date(hourEnd);
            }
        }

        return { dayKeys, grid };
    }, [props.shifts, days]);

    const actionableShifts = useMemo(
        () => props.shifts.filter(isActionableConflictShift),
        [props.shifts],
    );

    const timeOffConflicts = useMemo(() => {
        if (!props.timeOffs?.length)
            return [] as Array<{
                shift: ShiftLite;
                timeOffId: number;
                label: string;
            }>;
        const out: Array<{
            shift: ShiftLite;
            timeOffId: number;
            label: string;
        }> = [];
        for (const sh of actionableShifts) {
            if (!sh.user_id) continue;
            for (const t of props.timeOffs) {
                if (t.user_id !== sh.user_id) continue;
                if (
                    rangesOverlap(
                        sh.starts_at,
                        sh.ends_at,
                        t.starts_at,
                        t.ends_at,
                    )
                ) {
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
            list.sort(
                (x, y) =>
                    new Date(x.starts_at).getTime() -
                    new Date(y.starts_at).getTime(),
            );
            for (let i = 0; i < list.length - 1; i++) {
                const a = list[i];
                const b = list[i + 1];
                if (!a || !b) continue;
                if (
                    rangesOverlap(
                        a.starts_at,
                        a.ends_at,
                        b.starts_at,
                        b.ends_at,
                    )
                ) {
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
            list.sort(
                (x, y) =>
                    new Date(x.starts_at).getTime() -
                    new Date(y.starts_at).getTime(),
            );
            for (let i = 0; i < list.length - 1; i++) {
                const a = list[i];
                const b = list[i + 1];
                if (!a || !b) continue;
                if (
                    rangesOverlap(
                        a.starts_at,
                        a.ends_at,
                        b.starts_at,
                        b.ends_at,
                    )
                ) {
                    out.push({ clientId, a, b });
                }
            }
        }
        return out;
    }, [actionableShifts]);

    const historicalLockedOverlaps = useMemo(() => {
        const lockedShifts = props.shifts.filter(isShiftLocked);
        const out: Array<{
            kind: 'staff' | 'client';
            a: ShiftLite;
            b: ShiftLite;
        }> = [];

        const byStaff = new Map<number, ShiftLite[]>();
        for (const s of lockedShifts) {
            if (!s.user_id) continue;
            if (!byStaff.has(s.user_id)) byStaff.set(s.user_id, []);
            byStaff.get(s.user_id)!.push(s);
        }
        for (const [, list] of byStaff.entries()) {
            list.sort(
                (x, y) =>
                    new Date(x.starts_at).getTime() -
                    new Date(y.starts_at).getTime(),
            );
            for (let i = 0; i < list.length - 1; i++) {
                const a = list[i];
                const b = list[i + 1];
                if (!a || !b) continue;
                if (
                    rangesOverlap(
                        a.starts_at,
                        a.ends_at,
                        b.starts_at,
                        b.ends_at,
                    )
                ) {
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
            list.sort(
                (x, y) =>
                    new Date(x.starts_at).getTime() -
                    new Date(y.starts_at).getTime(),
            );
            for (let i = 0; i < list.length - 1; i++) {
                const a = list[i];
                const b = list[i + 1];
                if (!a || !b) continue;
                if (
                    rangesOverlap(
                        a.starts_at,
                        a.ends_at,
                        b.starts_at,
                        b.ends_at,
                    )
                ) {
                    out.push({ kind: 'client', a, b });
                }
            }
        }

        return out;
    }, [props.shifts]);

    const timesheetsNeedingAttention = useMemo(() => {
        if (props.canManageAny) {
            return props.shifts.filter(
                (s) => s.timesheet_status === 'submitted',
            );
        }
        return props.shifts.filter(
            (s) =>
                s.timesheet_status === 'draft' ||
                s.timesheet_status === 'returned',
        );
    }, [props.shifts, props.canManageAny]);

    const shiftsWithIncidents = useMemo(
        () => props.shifts.filter((s) => s.incidents_count > 0),
        [props.shifts],
    );

    const goWeek = (offsetDays: number) => {
        const target = ymd(addDays(startDate, offsetDays));
        router.get(
            rosteringIndex.url(),
            {
                week: target,
                staff_id: props.filters.staff_id ?? undefined,
                client_id: props.filters.client_id ?? undefined,
                site_id: props.filters.site_id ?? undefined,
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    const updateFilter = (next: Partial<Props['filters']>) => {
        router.get(
            rosteringIndex.url(),
            {
                week: props.filters.week,
                staff_id: next.staff_id ?? props.filters.staff_id ?? undefined,
                client_id:
                    next.client_id ?? props.filters.client_id ?? undefined,
                site_id: next.site_id ?? props.filters.site_id ?? undefined,
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    const selectedSiteName =
        props.sites.find((site) => site.id === props.filters.site_id)?.name ??
        null;
    const publishBlockCount =
        props.rosterPeriod?.validation_summary?.blocks?.length ?? 0;
    const publishWarningCount =
        props.rosterPeriod?.validation_summary?.warnings?.length ?? 0;

    const isPreviouslyPublished = Boolean(props.rosterPeriod?.published_at);
    const canRepublish = Boolean(
        props.rosterPeriod &&
        isPreviouslyPublished &&
        props.rosterPeriod.status !== 'published' &&
        props.rosterPeriod.status !== 'archived',
    );
    const diffTotal = props.rosterPeriod?.diff_summary?.total ?? 0;

    const postPeriodAction = (
        action: 'review' | 'publish' | 'republish' | 'unpublish',
    ) => {
        if (!props.rosterPeriod) return;

        router.post(
            `/operations/rostering/periods/${props.rosterPeriod.id}/${action}`,
            {},
            { preserveScroll: true },
        );
    };

    const generateSuggestions = () => {
        router.post(
            '/operations/rostering/auto-schedule',
            {
                week: props.weekStart,
                site_id: props.filters.site_id,
                client_id: props.filters.client_id,
            },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Rostering', href: rosteringIndex.url() }]}
        >
            <Head title="Rostering" />

            <div className="space-y-4 p-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <HeadingSmall
                            title="Rostering"
                            description="Week view of shifts with operational signals (tasks, incidents, timesheets)."
                        />
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => goWeek(-7)}
                        >
                            <ChevronLeft className="mr-1 h-4 w-4" /> Prev
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => goWeek(7)}
                        >
                            Next <ChevronRight className="ml-1 h-4 w-4" />
                        </Button>

                        <Separator
                            orientation="vertical"
                            className="mx-1 hidden h-8 md:block"
                        />

                        <Link href="/operations/shifts/create">
                            <Button size="sm">
                                <Plus className="mr-1 h-4 w-4" /> New shift
                            </Button>
                        </Link>
                        <Link href="/operations/rostering/conflicts">
                            <Button size="sm" variant="outline">
                                Conflict queue
                            </Button>
                        </Link>
                        <Link href="/operations/shifts/series">
                            <Button size="sm" variant="outline">
                                Recurring series
                            </Button>
                        </Link>
                        {props.rosteringFeatures.auto_schedule &&
                        props.canAutoScheduleRoster ? (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={!props.filters.site_id}
                                onClick={generateSuggestions}
                                data-test="rostering-suggest-assignments"
                            >
                                Suggest assignments
                            </Button>
                        ) : null}
                    </div>
                </div>

                {/* KPI Cards — 3 critical always visible, rest collapsible */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
                    <KpiCard
                        label="Total Shifts"
                        value={props.stats.total}
                        icon={Calendar}
                        color="bg-primary/10 text-primary"
                    />
                    <KpiCard
                        label="Open / Unfilled"
                        value={props.stats.open}
                        icon={AlertTriangle}
                        description="Need assignment"
                        color="bg-status-critical-bg text-status-critical"
                    />
                    <KpiCard
                        label="Coverage Rate"
                        value={props.analytics.coverageRate}
                        icon={TrendingUp}
                        suffix="%"
                        description="Filled / Total"
                        color="bg-status-info-bg text-status-info"
                    />
                </div>

                <Collapsible>
                    <CollapsibleTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="group flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <ChevronDown className="h-3.5 w-3.5 transition-transform group-data-[state=open]:rotate-180" />
                            <span className="group-data-[state=open]:hidden">
                                Show all metrics
                            </span>
                            <span className="hidden group-data-[state=open]:inline">
                                Hide metrics
                            </span>
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent className="space-y-3 pt-1">
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
                            <KpiCard
                                label="Staff Rostered"
                                value={props.analytics.staffRostered}
                                icon={Users}
                                color="bg-status-success-bg text-status-success"
                            />
                            <KpiCard
                                label="On Leave"
                                value={props.analytics.onLeaveCount}
                                icon={CalendarOff}
                                description="Approved this week"
                                color="bg-status-warning-bg text-status-warning"
                            />
                            <KpiCard
                                label="Coverage Gaps"
                                value={props.stats.coverage_gaps ?? 0}
                                icon={AlertTriangle}
                                description="Demand exceeds supply"
                                color="bg-status-critical-bg text-status-critical"
                            />
                        </div>

                        <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">
                                        This week
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    <div className="text-sm text-muted-foreground">
                                        {props.weekStart} →{' '}
                                        {ymd(addDays(startDate, 6))}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant="outline">
                                            Total: {props.stats.total}
                                        </Badge>
                                        <Badge
                                            variant={
                                                props.stats.open > 0
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            Open: {props.stats.open}
                                        </Badge>
                                        <Badge variant="outline">
                                            Scheduled: {props.stats.scheduled}
                                        </Badge>
                                        <Badge variant="outline">
                                            In progress:{' '}
                                            {props.stats.in_progress}
                                        </Badge>
                                        <Badge variant="outline">
                                            Draft: {props.stats.draft}
                                        </Badge>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">
                                        Operational signals
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-wrap gap-2">
                                    <Badge
                                        variant={
                                            props.stats.incidents > 0
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        Incidents: {props.stats.incidents}
                                    </Badge>
                                    <Badge
                                        variant={
                                            props.stats.timesheets_pending > 0
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        Timesheets pending:{' '}
                                        {props.stats.timesheets_pending}
                                    </Badge>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">
                                        Overlaps
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-wrap gap-2">
                                    <Badge
                                        variant={
                                            props.stats.staff_overlaps > 0
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        Staff overlaps:{' '}
                                        {props.stats.staff_overlaps}
                                    </Badge>
                                    <Badge
                                        variant={
                                            props.stats.client_overlaps > 0
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        {clientSingular} overlaps:{' '}
                                        {props.stats.client_overlaps}
                                    </Badge>
                                    <Badge
                                        variant={
                                            props.stats.time_off_conflicts > 0
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        Time-off conflicts:{' '}
                                        {props.stats.time_off_conflicts}
                                    </Badge>
                                    <Badge
                                        variant={
                                            (props.stats.coverage_gaps ?? 0) > 0
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        Coverage gaps:{' '}
                                        {props.stats.coverage_gaps ?? 0}
                                    </Badge>
                                </CardContent>
                            </Card>
                        </div>
                    </CollapsibleContent>
                </Collapsible>

                {/* Filters — always visible */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">
                            Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {props.canManageAny ? (
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                <Select
                                    value={
                                        props.filters.staff_id
                                            ? String(props.filters.staff_id)
                                            : 'all'
                                    }
                                    onValueChange={(v) =>
                                        updateFilter({
                                            staff_id:
                                                v === 'all' ? null : Number(v),
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All staff" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All staff
                                        </SelectItem>
                                        {props.staff.map((s) => (
                                            <SelectItem
                                                key={s.id}
                                                value={String(s.id)}
                                            >
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <Select
                                    value={
                                        props.filters.site_id
                                            ? String(props.filters.site_id)
                                            : 'all'
                                    }
                                    onValueChange={(v) =>
                                        updateFilter({
                                            site_id:
                                                v === 'all' ? null : Number(v),
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All sites" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All sites
                                        </SelectItem>
                                        {props.sites.map((site) => (
                                            <SelectItem
                                                key={site.id}
                                                value={String(site.id)}
                                            >
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <Select
                                    value={
                                        props.filters.client_id
                                            ? String(props.filters.client_id)
                                            : 'all'
                                    }
                                    onValueChange={(v) =>
                                        updateFilter({
                                            client_id:
                                                v === 'all' ? null : Number(v),
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder={`All ${clientPlural.toLowerCase()}`}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">{`All ${clientPlural.toLowerCase()}`}</SelectItem>
                                        {props.clients.map((c) => (
                                            <SelectItem
                                                key={c.id}
                                                value={String(c.id)}
                                            >
                                                {c.first_name} {c.last_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : (
                            <div className="text-sm text-muted-foreground">
                                You are viewing your assigned shifts.
                            </div>
                        )}
                    </CardContent>
                </Card>

                {props.rosteringFeatures.publish && props.canPublishRoster ? (
                    <Card data-test="rostering-publish-panel">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center justify-between gap-2 text-sm font-medium">
                                <span>Publish status</span>
                                {props.rosterPeriod ? (
                                    <Badge
                                        variant={
                                            props.rosterPeriod.status ===
                                            'published'
                                                ? 'default'
                                                : props.rosterPeriod.status ===
                                                    'changed_after_publish'
                                                  ? 'destructive'
                                                  : 'outline'
                                        }
                                    >
                                        {props.rosterPeriod.status.replaceAll(
                                            '_',
                                            ' ',
                                        )}
                                    </Badge>
                                ) : (
                                    <Badge variant="outline">No site</Badge>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div className="space-y-1 text-sm">
                                <div className="font-medium">
                                    {selectedSiteName ??
                                        'Choose a site to manage publishing'}
                                </div>
                                {props.rosterPeriod ? (
                                    <div className="text-muted-foreground">
                                        Version {props.rosterPeriod.version} ·{' '}
                                        {publishBlockCount} blockers ·{' '}
                                        {publishWarningCount} warnings
                                        {diffTotal > 0
                                            ? ` · ${diffTotal} changes`
                                            : ''}
                                    </div>
                                ) : (
                                    <div className="text-muted-foreground">
                                        Publishing is tracked per site and week.
                                    </div>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={
                                        !props.rosterPeriod ||
                                        props.rosterPeriod.status === 'archived'
                                    }
                                    onClick={() => postPeriodAction('review')}
                                    data-test="rostering-review-publish"
                                >
                                    Review
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={
                                        !props.rosterPeriod ||
                                        publishBlockCount > 0 ||
                                        props.rosterPeriod.status === 'archived'
                                    }
                                    onClick={() =>
                                        postPeriodAction(
                                            canRepublish
                                                ? 'republish'
                                                : 'publish',
                                        )
                                    }
                                    data-test="rostering-confirm-publish"
                                >
                                    <CheckCircle2 className="mr-1 h-4 w-4" />
                                    {canRepublish ? 'Re-publish' : 'Publish'}
                                </Button>
                                {props.rosterPeriod && diffTotal > 0 ? (
                                    <Link
                                        href={`/operations/rostering/periods/${props.rosterPeriod.id}/diff`}
                                    >
                                        <Button size="sm" variant="outline">
                                            View diff
                                        </Button>
                                    </Link>
                                ) : null}
                                {props.rosterPeriod?.status === 'published' ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            postPeriodAction('unpublish')
                                        }
                                    >
                                        Unpublish
                                    </Button>
                                ) : null}
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                <Tabs
                    tabs={[
                        {
                            key: 'ops',
                            label: (
                                <span className="flex items-center gap-2">
                                    Ops
                                    {props.stats.open +
                                        props.stats.staff_overlaps +
                                        props.stats.client_overlaps +
                                        props.stats.time_off_conflicts >
                                    0 ? (
                                        <Badge
                                            variant="destructive"
                                            className="text-[10px]"
                                        >
                                            action
                                        </Badge>
                                    ) : (
                                        <Badge
                                            variant="outline"
                                            className="text-[10px]"
                                        >
                                            ok
                                        </Badge>
                                    )}
                                </span>
                            ),
                            content: (
                                <div className="space-y-4">
                                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                                        <Card className="lg:col-span-2">
                                            <CardHeader className="pb-2">
                                                <CardTitle className="text-base">
                                                    Fix now
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent className="space-y-4">
                                                {/* Open shifts */}
                                                <div className="space-y-2">
                                                    <div className="flex items-center justify-between">
                                                        <div className="text-sm font-medium">
                                                            Open shifts
                                                        </div>
                                                        <Badge
                                                            variant={
                                                                openShifts.length >
                                                                0
                                                                    ? 'default'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {openShifts.length}
                                                        </Badge>
                                                    </div>
                                                    {openShifts.length === 0 ? (
                                                        <div className="text-sm text-muted-foreground">
                                                            No open shifts in
                                                            this week.
                                                        </div>
                                                    ) : (
                                                        <div className="space-y-2">
                                                            {openShifts
                                                                .slice(0, 8)
                                                                .map((sh) => (
                                                                    <div
                                                                        key={
                                                                            sh.id
                                                                        }
                                                                        className="rounded-md border p-3"
                                                                    >
                                                                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                                                            <div>
                                                                                <div className="text-sm font-medium">
                                                                                    {sh.client ??
                                                                                        clientSingular}{' '}
                                                                                    ·{' '}
                                                                                    {new Date(
                                                                                        sh.starts_at,
                                                                                    ).toLocaleDateString()}{' '}
                                                                                    {fmtTime(
                                                                                        sh.starts_at,
                                                                                    )}

                                                                                    –
                                                                                    {fmtTime(
                                                                                        sh.ends_at,
                                                                                    )}
                                                                                </div>
                                                                                <div className="mt-1 flex flex-wrap gap-1.5 text-xs text-muted-foreground">
                                                                                    <span>
                                                                                        {sh.location
                                                                                            ? `${sh.location} · `
                                                                                            : ''}
                                                                                        Status:{' '}
                                                                                        {
                                                                                            sh.status
                                                                                        }
                                                                                    </span>
                                                                                    {sh.shift_series_id ? (
                                                                                        <Badge
                                                                                            variant="outline"
                                                                                            className="text-[10px]"
                                                                                        >
                                                                                            Recurring
                                                                                        </Badge>
                                                                                    ) : null}
                                                                                    {sh.has_active_replacement ? (
                                                                                        <Badge
                                                                                            variant={replacementBadgeVariant(
                                                                                                sh.replacement_status,
                                                                                            )}
                                                                                            className="text-[10px]"
                                                                                        >
                                                                                            Replacement
                                                                                        </Badge>
                                                                                    ) : null}
                                                                                </div>
                                                                            </div>

                                                                            <div className="flex flex-wrap items-center gap-2">
                                                                                {props.canManageAny ? (
                                                                                    <>
                                                                                        <Select
                                                                                            value={
                                                                                                opsAssignSelection[
                                                                                                    sh
                                                                                                        .id
                                                                                                ] ??
                                                                                                ''
                                                                                            }
                                                                                            onValueChange={(
                                                                                                v,
                                                                                            ) =>
                                                                                                setOpsAssignSelection(
                                                                                                    (
                                                                                                        prev,
                                                                                                    ) => ({
                                                                                                        ...prev,
                                                                                                        [sh.id]:
                                                                                                            v,
                                                                                                    }),
                                                                                                )
                                                                                            }
                                                                                        >
                                                                                            <SelectTrigger className="w-[220px]">
                                                                                                <SelectValue placeholder="Assign staff" />
                                                                                            </SelectTrigger>
                                                                                            <SelectContent>
                                                                                                {availableStaffForShift(
                                                                                                    sh,
                                                                                                ).map(
                                                                                                    (
                                                                                                        s,
                                                                                                    ) => (
                                                                                                        <SelectItem
                                                                                                            key={
                                                                                                                s.id
                                                                                                            }
                                                                                                            value={String(
                                                                                                                s.id,
                                                                                                            )}
                                                                                                        >
                                                                                                            {
                                                                                                                s.name
                                                                                                            }
                                                                                                        </SelectItem>
                                                                                                    ),
                                                                                                )}
                                                                                            </SelectContent>
                                                                                        </Select>
                                                                                        <Button
                                                                                            size="sm"
                                                                                            disabled={
                                                                                                !opsAssignSelection[
                                                                                                    sh
                                                                                                        .id
                                                                                                ]
                                                                                            }
                                                                                            onClick={() => {
                                                                                                const userId =
                                                                                                    opsAssignSelection[
                                                                                                        sh
                                                                                                            .id
                                                                                                    ];
                                                                                                if (
                                                                                                    !userId
                                                                                                )
                                                                                                    return;
                                                                                                router.post(
                                                                                                    `/operations/shifts/${sh.id}/assign`,
                                                                                                    {
                                                                                                        user_id:
                                                                                                            userId,
                                                                                                        return_to:
                                                                                                            '/operations/rostering',
                                                                                                    },
                                                                                                    {
                                                                                                        preserveScroll: true,
                                                                                                        onSuccess:
                                                                                                            () =>
                                                                                                                setOpsAssignSelection(
                                                                                                                    (
                                                                                                                        prev,
                                                                                                                    ) => ({
                                                                                                                        ...prev,
                                                                                                                        [sh.id]:
                                                                                                                            '',
                                                                                                                    }),
                                                                                                                ),
                                                                                                    },
                                                                                                );
                                                                                            }}
                                                                                        >
                                                                                            Assign
                                                                                        </Button>
                                                                                    </>
                                                                                ) : null}
                                                                                <Link
                                                                                    href={`/operations/shifts/${sh.id}`}
                                                                                >
                                                                                    <Button
                                                                                        size="sm"
                                                                                        variant="outline"
                                                                                    >
                                                                                        Open
                                                                                    </Button>
                                                                                </Link>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                ))}
                                                            {openShifts.length >
                                                            8 ? (
                                                                <div className="text-xs text-muted-foreground">
                                                                    Showing 8 of{' '}
                                                                    {
                                                                        openShifts.length
                                                                    }{' '}
                                                                    open shifts.
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                    )}
                                                </div>

                                                <Separator />

                                                {/* Conflicts */}
                                                <div className="space-y-3">
                                                    <div className="flex items-center justify-between">
                                                        <div className="text-sm font-medium">
                                                            Conflicts
                                                        </div>
                                                        <div className="flex flex-wrap gap-2">
                                                            <Badge
                                                                variant={
                                                                    timeOffConflicts.length >
                                                                    0
                                                                        ? 'destructive'
                                                                        : 'outline'
                                                                }
                                                            >
                                                                Time-off:{' '}
                                                                {
                                                                    timeOffConflicts.length
                                                                }
                                                            </Badge>
                                                            <Badge
                                                                variant={
                                                                    staffOverlapsDetailed.length >
                                                                    0
                                                                        ? 'destructive'
                                                                        : 'outline'
                                                                }
                                                            >
                                                                Staff overlaps:{' '}
                                                                {
                                                                    staffOverlapsDetailed.length
                                                                }
                                                            </Badge>
                                                            <Badge
                                                                variant={
                                                                    clientOverlapsDetailed.length >
                                                                    0
                                                                        ? 'destructive'
                                                                        : 'outline'
                                                                }
                                                            >
                                                                Client overlaps:{' '}
                                                                {
                                                                    clientOverlapsDetailed.length
                                                                }
                                                            </Badge>
                                                            <Badge
                                                                variant={
                                                                    historicalLockedOverlaps.length >
                                                                    0
                                                                        ? 'secondary'
                                                                        : 'outline'
                                                                }
                                                            >
                                                                Historical
                                                                (locked):{' '}
                                                                {
                                                                    historicalLockedOverlaps.length
                                                                }
                                                            </Badge>
                                                        </div>
                                                    </div>

                                                    {timeOffConflicts.length ===
                                                        0 &&
                                                    staffOverlapsDetailed.length ===
                                                        0 &&
                                                    clientOverlapsDetailed.length ===
                                                        0 ? (
                                                        <div className="text-sm text-muted-foreground">
                                                            No actionable
                                                            conflicts detected
                                                            in this roster
                                                            window.
                                                        </div>
                                                    ) : (
                                                        <div className="space-y-2">
                                                            {timeOffConflicts
                                                                .slice(0, 4)
                                                                .map(
                                                                    ({
                                                                        shift,
                                                                        timeOffId,
                                                                        label,
                                                                    }) => (
                                                                        <div
                                                                            key={`to-${shift.id}-${timeOffId}`}
                                                                            className="rounded-md border p-3"
                                                                        >
                                                                            <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                                                                <div>
                                                                                    <div className="text-sm font-medium">
                                                                                        Time-off
                                                                                        conflict
                                                                                        ·{' '}
                                                                                        {shift.staff ??
                                                                                            'Staff'}
                                                                                    </div>
                                                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                                                        {shift.client ??
                                                                                            clientSingular}{' '}
                                                                                        ·{' '}
                                                                                        {new Date(
                                                                                            shift.starts_at,
                                                                                        ).toLocaleDateString()}{' '}
                                                                                        {fmtTime(
                                                                                            shift.starts_at,
                                                                                        )}

                                                                                        –
                                                                                        {fmtTime(
                                                                                            shift.ends_at,
                                                                                        )}
                                                                                    </div>
                                                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                                                        {
                                                                                            label
                                                                                        }
                                                                                    </div>
                                                                                </div>
                                                                                <div className="flex flex-wrap items-center gap-2">
                                                                                    <Link
                                                                                        href={`/operations/shifts/${shift.id}`}
                                                                                    >
                                                                                        <Button
                                                                                            size="sm"
                                                                                            variant="outline"
                                                                                        >
                                                                                            Open
                                                                                            shift
                                                                                        </Button>
                                                                                    </Link>
                                                                                    {props.canManageAny ? (
                                                                                        <Button
                                                                                            size="sm"
                                                                                            variant="destructive"
                                                                                            onClick={() => {
                                                                                                router.delete(
                                                                                                    destroyTimeOff.url(
                                                                                                        timeOffId,
                                                                                                    ),
                                                                                                    {
                                                                                                        preserveScroll: true,
                                                                                                    },
                                                                                                );
                                                                                            }}
                                                                                        >
                                                                                            Remove
                                                                                            time-off
                                                                                        </Button>
                                                                                    ) : null}
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    ),
                                                                )}

                                                            {staffOverlapsDetailed
                                                                .slice(0, 4)
                                                                .map(
                                                                    ({
                                                                        a,
                                                                        b,
                                                                    }) => (
                                                                        <div
                                                                            key={`so-${a.id}-${b.id}`}
                                                                            className="rounded-md border p-3"
                                                                        >
                                                                            <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                                                                <div>
                                                                                    <div className="text-sm font-medium">
                                                                                        Staff
                                                                                        overlap
                                                                                        ·{' '}
                                                                                        {a.staff ??
                                                                                            'Staff'}
                                                                                    </div>
                                                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                                                        A:{' '}
                                                                                        {new Date(
                                                                                            a.starts_at,
                                                                                        ).toLocaleDateString()}{' '}
                                                                                        {fmtTime(
                                                                                            a.starts_at,
                                                                                        )}

                                                                                        –
                                                                                        {fmtTime(
                                                                                            a.ends_at,
                                                                                        )}{' '}
                                                                                        ·{' '}
                                                                                        {a.client ??
                                                                                            clientSingular}
                                                                                    </div>
                                                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                                                        B:{' '}
                                                                                        {new Date(
                                                                                            b.starts_at,
                                                                                        ).toLocaleDateString()}{' '}
                                                                                        {fmtTime(
                                                                                            b.starts_at,
                                                                                        )}

                                                                                        –
                                                                                        {fmtTime(
                                                                                            b.ends_at,
                                                                                        )}{' '}
                                                                                        ·{' '}
                                                                                        {b.client ??
                                                                                            clientSingular}
                                                                                    </div>
                                                                                </div>
                                                                                <div className="flex flex-wrap items-center gap-2">
                                                                                    <Link
                                                                                        href={`/operations/shifts/${a.id}`}
                                                                                    >
                                                                                        <Button
                                                                                            size="sm"
                                                                                            variant="outline"
                                                                                        >
                                                                                            Open
                                                                                            A
                                                                                        </Button>
                                                                                    </Link>
                                                                                    <Link
                                                                                        href={`/operations/shifts/${b.id}`}
                                                                                    >
                                                                                        <Button
                                                                                            size="sm"
                                                                                            variant="outline"
                                                                                        >
                                                                                            Open
                                                                                            B
                                                                                        </Button>
                                                                                    </Link>
                                                                                    <Button
                                                                                        size="sm"
                                                                                        variant="default"
                                                                                        onClick={() =>
                                                                                            setResolveModal(
                                                                                                {
                                                                                                    kind: 'staff',
                                                                                                    a,
                                                                                                    b,
                                                                                                    staffId:
                                                                                                        a.user_id ??
                                                                                                        b.user_id ??
                                                                                                        undefined,
                                                                                                },
                                                                                            )
                                                                                        }
                                                                                    >
                                                                                        Resolve
                                                                                    </Button>
                                                                                    {props.canManageAny ? (
                                                                                        <Button
                                                                                            size="sm"
                                                                                            variant="destructive"
                                                                                            onClick={() => {
                                                                                                router.post(
                                                                                                    `/operations/shifts/${b.id}/unassign`,
                                                                                                    {
                                                                                                        return_to:
                                                                                                            '/operations/rostering',
                                                                                                    },
                                                                                                    {
                                                                                                        preserveScroll: true,
                                                                                                    },
                                                                                                );
                                                                                            }}
                                                                                        >
                                                                                            Unassign
                                                                                            B
                                                                                        </Button>
                                                                                    ) : null}
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    ),
                                                                )}

                                                            {clientOverlapsDetailed
                                                                .slice(0, 4)
                                                                .map(
                                                                    ({
                                                                        clientId,
                                                                        a,
                                                                        b,
                                                                    }) => (
                                                                        <div
                                                                            key={`co-${a.id}-${b.id}`}
                                                                            className="rounded-md border p-3"
                                                                        >
                                                                            <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                                                                <div>
                                                                                    <div className="text-sm font-medium">
                                                                                        {
                                                                                            clientSingular
                                                                                        }{' '}
                                                                                        overlap
                                                                                        ·{' '}
                                                                                        {a.client ??
                                                                                            clientSingular}
                                                                                    </div>
                                                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                                                        A:{' '}
                                                                                        {new Date(
                                                                                            a.starts_at,
                                                                                        ).toLocaleDateString()}{' '}
                                                                                        {fmtTime(
                                                                                            a.starts_at,
                                                                                        )}

                                                                                        –
                                                                                        {fmtTime(
                                                                                            a.ends_at,
                                                                                        )}{' '}
                                                                                        ·{' '}
                                                                                        {a.staff ??
                                                                                            'Staff'}
                                                                                    </div>
                                                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                                                        B:{' '}
                                                                                        {new Date(
                                                                                            b.starts_at,
                                                                                        ).toLocaleDateString()}{' '}
                                                                                        {fmtTime(
                                                                                            b.starts_at,
                                                                                        )}

                                                                                        –
                                                                                        {fmtTime(
                                                                                            b.ends_at,
                                                                                        )}{' '}
                                                                                        ·{' '}
                                                                                        {b.staff ??
                                                                                            'Staff'}
                                                                                    </div>
                                                                                </div>
                                                                                <div className="flex flex-wrap items-center gap-2">
                                                                                    <Link
                                                                                        href={`/operations/shifts/${a.id}`}
                                                                                    >
                                                                                        <Button
                                                                                            size="sm"
                                                                                            variant="outline"
                                                                                        >
                                                                                            Open
                                                                                            A
                                                                                        </Button>
                                                                                    </Link>
                                                                                    <Link
                                                                                        href={`/operations/shifts/${b.id}`}
                                                                                    >
                                                                                        <Button
                                                                                            size="sm"
                                                                                            variant="outline"
                                                                                        >
                                                                                            Open
                                                                                            B
                                                                                        </Button>
                                                                                    </Link>
                                                                                    <Button
                                                                                        size="sm"
                                                                                        variant="default"
                                                                                        onClick={() =>
                                                                                            setResolveModal(
                                                                                                {
                                                                                                    kind: 'client',
                                                                                                    a,
                                                                                                    b,
                                                                                                    clientId,
                                                                                                },
                                                                                            )
                                                                                        }
                                                                                    >
                                                                                        Resolve
                                                                                    </Button>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    ),
                                                                )}
                                                        </div>
                                                    )}

                                                    {historicalLockedOverlaps.length >
                                                    0 ? (
                                                        <div className="rounded-md border border-dashed p-3">
                                                            <div className="text-sm font-medium">
                                                                Historical
                                                                overlaps (both
                                                                shifts locked)
                                                            </div>
                                                            <div className="mt-1 text-xs text-muted-foreground">
                                                                These are
                                                                non-actionable
                                                                in rostering.
                                                                Reopen a shift
                                                                only if an audit
                                                                correction is
                                                                required.
                                                            </div>
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </CardContent>
                                        </Card>

                                        <div className="space-y-3">
                                            <Card
                                                className={
                                                    props.coverageAlerts
                                                        .length > 0
                                                        ? 'border-status-critical/20'
                                                        : ''
                                                }
                                            >
                                                <CardHeader className="pb-2">
                                                    <CardTitle className="text-base">
                                                        Coverage demand
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent className="space-y-3">
                                                    <div className="flex items-center justify-between">
                                                        <div className="text-sm font-medium">
                                                            Under-covered site
                                                            windows
                                                        </div>
                                                        <Badge
                                                            variant={
                                                                props
                                                                    .coverageAlerts
                                                                    .length > 0
                                                                    ? 'destructive'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {
                                                                props
                                                                    .coverageAlerts
                                                                    .length
                                                            }
                                                        </Badge>
                                                    </div>
                                                    <div className="flex items-center justify-between rounded-md border border-dashed p-2 text-xs text-muted-foreground">
                                                        <span>
                                                            Recurring demand
                                                            drift
                                                        </span>
                                                        <span className="font-medium text-foreground">
                                                            {
                                                                recurringCoverageDriftCount
                                                            }
                                                        </span>
                                                    </div>
                                                    {props.coverageAlerts
                                                        .length === 0 ? (
                                                        <div className="text-sm text-muted-foreground">
                                                            No projected site
                                                            coverage gaps this
                                                            week.
                                                        </div>
                                                    ) : (
                                                        <div className="space-y-2">
                                                            {props.coverageAlerts
                                                                .slice(0, 6)
                                                                .map(
                                                                    (
                                                                        alert,
                                                                        index,
                                                                    ) => (
                                                                        <div
                                                                            key={`${alert.site_id}-${alert.rule_name}-${alert.window_label}-${index}`}
                                                                            className="rounded-xl border p-3"
                                                                        >
                                                                            <div className="flex items-start justify-between gap-2">
                                                                                <div>
                                                                                    <div className="text-sm font-medium">
                                                                                        {
                                                                                            alert.site_name
                                                                                        }
                                                                                    </div>
                                                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                                                        {
                                                                                            alert.rule_name
                                                                                        }{' '}
                                                                                        ·{' '}
                                                                                        {
                                                                                            alert.window_label
                                                                                        }
                                                                                    </div>
                                                                                </div>
                                                                                <Badge variant="destructive">
                                                                                    {gapKindLabel(
                                                                                        alert.gap_kind,
                                                                                    )}
                                                                                </Badge>
                                                                            </div>

                                                                            <div className="mt-2 text-xs text-foreground">
                                                                                Need{' '}
                                                                                {
                                                                                    alert.required_staff
                                                                                }{' '}
                                                                                staff,
                                                                                only{' '}
                                                                                {
                                                                                    alert.assigned_staff
                                                                                }{' '}
                                                                                assigned.
                                                                                {(alert.planned_staff ??
                                                                                    alert.assigned_staff) >
                                                                                alert.assigned_staff
                                                                                    ? ` ${alert.planned_staff ?? alert.assigned_staff} planned once open shifts are filled.`
                                                                                    : ''}
                                                                            </div>
                                                                            {coverageRolesForAction(
                                                                                alert,
                                                                            )
                                                                                .length >
                                                                            0 ? (
                                                                                <div className="mt-2 flex flex-wrap gap-2">
                                                                                    {coverageRolesForAction(
                                                                                        alert,
                                                                                    ).map(
                                                                                        (
                                                                                            role,
                                                                                        ) => (
                                                                                            <Badge
                                                                                                key={`${alert.site_id}-${alert.rule_name}-${role.key}`}
                                                                                                variant="outline"
                                                                                            >
                                                                                                {role.label ??
                                                                                                    role.key.replace(
                                                                                                        /_/g,
                                                                                                        ' ',
                                                                                                    )}{' '}
                                                                                                still
                                                                                                needed
                                                                                                x
                                                                                                {role.missing ??
                                                                                                    1}
                                                                                            </Badge>
                                                                                        ),
                                                                                    )}
                                                                                </div>
                                                                            ) : null}
                                                                            {alert.contradictions &&
                                                                            alert
                                                                                .contradictions
                                                                                .length >
                                                                                0 ? (
                                                                                <div className="mt-2 flex flex-wrap gap-2">
                                                                                    {alert.contradictions.map(
                                                                                        (
                                                                                            issue,
                                                                                        ) => (
                                                                                            <Badge
                                                                                                key={`${alert.site_id}-${issue}`}
                                                                                                variant="outline"
                                                                                            >
                                                                                                {issue ===
                                                                                                'headcount_exact_but_role_gap'
                                                                                                    ? 'Headcount looks full but role demand is still short'
                                                                                                    : issue ===
                                                                                                        'partial_window_undercoverage'
                                                                                                      ? 'Coverage drops away inside the window and needs partial backfill'
                                                                                                      : issue ===
                                                                                                          'planned_supply_exact_but_role_gap'
                                                                                                        ? 'Planned supply still misses the required role mix'
                                                                                                        : issue ===
                                                                                                            'preferred_client_drift'
                                                                                                          ? 'Preferred client context has drifted'
                                                                                                          : issue ===
                                                                                                              'overfill_not_allowed'
                                                                                                            ? 'This window is overstaffed beyond the allowed limit'
                                                                                                            : issue ===
                                                                                                                'overfilled_but_wrong_role_mix'
                                                                                                              ? 'This window is overfilled but still has the wrong role mix'
                                                                                                              : issue}
                                                                                            </Badge>
                                                                                        ),
                                                                                    )}
                                                                                </div>
                                                                            ) : null}

                                                                            <div className="mt-3 flex flex-wrap gap-2">
                                                                                {alert.open_shift_ids &&
                                                                                alert
                                                                                    .open_shift_ids
                                                                                    .length >
                                                                                    0 ? (
                                                                                    <Link
                                                                                        href={`/operations/shifts/${alert.open_shift_ids[0]}`}
                                                                                    >
                                                                                        <Button
                                                                                            size="sm"
                                                                                            variant="outline"
                                                                                        >
                                                                                            Open
                                                                                            cover
                                                                                            shift
                                                                                        </Button>
                                                                                    </Link>
                                                                                ) : null}
                                                                                <Link href="/operations/rostering/conflicts">
                                                                                    <Button
                                                                                        size="sm"
                                                                                        variant="outline"
                                                                                    >
                                                                                        Open
                                                                                        queue
                                                                                    </Button>
                                                                                </Link>
                                                                                {shouldOfferCreation(
                                                                                    alert.recommended_fill_action,
                                                                                ) ? (
                                                                                    <>
                                                                                        <Link
                                                                                            href={buildCoverageCreateHref(
                                                                                                alert,
                                                                                            )}
                                                                                        >
                                                                                            <Button size="sm">
                                                                                                {fillActionLabel(
                                                                                                    alert.recommended_fill_action,
                                                                                                )}
                                                                                            </Button>
                                                                                        </Link>
                                                                                        <Link
                                                                                            href={buildCoverageCreateHref(
                                                                                                alert,
                                                                                                {
                                                                                                    openShift: true,
                                                                                                },
                                                                                            )}
                                                                                        >
                                                                                            <Button
                                                                                                size="sm"
                                                                                                variant="outline"
                                                                                            >
                                                                                                Create
                                                                                                open
                                                                                                shift
                                                                                            </Button>
                                                                                        </Link>
                                                                                        <Link
                                                                                            href={buildCoverageCreateHref(
                                                                                                alert,
                                                                                                {
                                                                                                    openShift: true,
                                                                                                    repeatWeekly: true,
                                                                                                },
                                                                                            )}
                                                                                        >
                                                                                            <Button
                                                                                                size="sm"
                                                                                                variant="outline"
                                                                                            >
                                                                                                Recurring
                                                                                                cover
                                                                                            </Button>
                                                                                        </Link>
                                                                                    </>
                                                                                ) : null}
                                                                            </div>
                                                                        </div>
                                                                    ),
                                                                )}
                                                        </div>
                                                    )}
                                                </CardContent>
                                            </Card>

                                            {/* ── Eligibility Alerts ─────────────────── */}
                                            {props.canManageAny ? (
                                                <Card
                                                    className={
                                                        props.eligibilityAlerts
                                                            .counts.blocked > 0
                                                            ? 'border-status-critical/20'
                                                            : props
                                                                    .eligibilityAlerts
                                                                    .counts
                                                                    .warnings >
                                                                0
                                                              ? 'border-status-warning/20'
                                                              : ''
                                                    }
                                                >
                                                    <CardHeader className="pb-2">
                                                        <div className="flex items-center justify-between">
                                                            <CardTitle className="text-base">
                                                                Eligibility
                                                                alerts
                                                            </CardTitle>
                                                            <div className="text-xs text-muted-foreground">
                                                                Next 14 days
                                                            </div>
                                                        </div>
                                                    </CardHeader>
                                                    <CardContent className="space-y-4">
                                                        {/* Stat row */}
                                                        <div className="grid grid-cols-4 gap-3">
                                                            <div className="rounded-md border p-2 text-center">
                                                                <div className="text-lg font-bold text-status-success">
                                                                    {
                                                                        props
                                                                            .eligibilityAlerts
                                                                            .counts
                                                                            .eligible
                                                                    }
                                                                </div>
                                                                <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                                                    Eligible
                                                                </div>
                                                            </div>
                                                            <div className="rounded-md border p-2 text-center">
                                                                <div className="text-lg font-bold text-status-warning">
                                                                    {
                                                                        props
                                                                            .eligibilityAlerts
                                                                            .counts
                                                                            .warnings
                                                                    }
                                                                </div>
                                                                <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                                                    Warnings
                                                                </div>
                                                            </div>
                                                            <div className="rounded-md border p-2 text-center">
                                                                <div className="text-lg font-bold text-status-critical">
                                                                    {
                                                                        props
                                                                            .eligibilityAlerts
                                                                            .counts
                                                                            .blocked
                                                                    }
                                                                </div>
                                                                <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                                                    Blocked
                                                                </div>
                                                            </div>
                                                            <div className="rounded-md border p-2 text-center">
                                                                <div className="text-lg font-bold text-muted-foreground">
                                                                    {
                                                                        props
                                                                            .eligibilityAlerts
                                                                            .counts
                                                                            .overrides
                                                                    }
                                                                </div>
                                                                <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                                                    Overrides
                                                                    (7d)
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {/* Blocked shifts table */}
                                                        {props.eligibilityAlerts
                                                            .blocked.length >
                                                        0 ? (
                                                            <div className="space-y-2">
                                                                <div className="flex items-center gap-2 text-xs font-medium tracking-wider text-status-critical uppercase dark:text-status-critical">
                                                                    <XCircle className="size-3" />
                                                                    Blocked
                                                                    shifts —
                                                                    requires
                                                                    action
                                                                </div>
                                                                <div className="divide-y rounded-md border border-status-critical/30 dark:border-status-critical/30">
                                                                    {props.eligibilityAlerts.blocked.map(
                                                                        (s) => (
                                                                            <div
                                                                                key={
                                                                                    s.id
                                                                                }
                                                                                className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                                                            >
                                                                                <div className="min-w-0 flex-1">
                                                                                    <div className="font-medium">
                                                                                        {new Date(
                                                                                            s.starts_at,
                                                                                        ).toLocaleDateString(
                                                                                            'en-NZ',
                                                                                            {
                                                                                                weekday:
                                                                                                    'short',
                                                                                                day: 'numeric',
                                                                                                month: 'short',
                                                                                                hour: '2-digit',
                                                                                                minute: '2-digit',
                                                                                            },
                                                                                        )}
                                                                                    </div>
                                                                                    <div className="truncate text-xs text-muted-foreground">
                                                                                        {
                                                                                            s.staff
                                                                                        }{' '}
                                                                                        ·{' '}
                                                                                        {
                                                                                            s.site
                                                                                        }
                                                                                    </div>
                                                                                    <div className="truncate text-xs text-status-critical dark:text-status-critical">
                                                                                        {
                                                                                            s.reason
                                                                                        }
                                                                                    </div>
                                                                                </div>
                                                                                <Button
                                                                                    size="sm"
                                                                                    variant="outline"
                                                                                    asChild
                                                                                >
                                                                                    <Link
                                                                                        href={`/operations/shifts/${s.id}`}
                                                                                    >
                                                                                        View
                                                                                    </Link>
                                                                                </Button>
                                                                            </div>
                                                                        ),
                                                                    )}
                                                                </div>
                                                            </div>
                                                        ) : null}

                                                        {/* Warning shifts table */}
                                                        {props.eligibilityAlerts
                                                            .warnings.length >
                                                        0 ? (
                                                            <div className="space-y-2">
                                                                <div className="flex items-center gap-2 text-xs font-medium tracking-wider text-status-warning uppercase dark:text-status-warning">
                                                                    <AlertTriangle className="size-3" />
                                                                    Warning
                                                                    shifts —
                                                                    review
                                                                    recommended
                                                                </div>
                                                                <div className="divide-y rounded-md border border-status-warning/30 dark:border-status-warning/30">
                                                                    {props.eligibilityAlerts.warnings.map(
                                                                        (s) => (
                                                                            <div
                                                                                key={
                                                                                    s.id
                                                                                }
                                                                                className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                                                            >
                                                                                <div className="min-w-0 flex-1">
                                                                                    <div className="font-medium">
                                                                                        {new Date(
                                                                                            s.starts_at,
                                                                                        ).toLocaleDateString(
                                                                                            'en-NZ',
                                                                                            {
                                                                                                weekday:
                                                                                                    'short',
                                                                                                day: 'numeric',
                                                                                                month: 'short',
                                                                                                hour: '2-digit',
                                                                                                minute: '2-digit',
                                                                                            },
                                                                                        )}
                                                                                    </div>
                                                                                    <div className="truncate text-xs text-muted-foreground">
                                                                                        {
                                                                                            s.staff
                                                                                        }{' '}
                                                                                        ·{' '}
                                                                                        {
                                                                                            s.site
                                                                                        }
                                                                                    </div>
                                                                                    <div className="truncate text-xs text-status-warning dark:text-status-warning">
                                                                                        {
                                                                                            s.reason
                                                                                        }
                                                                                    </div>
                                                                                </div>
                                                                                <Button
                                                                                    size="sm"
                                                                                    variant="outline"
                                                                                    asChild
                                                                                >
                                                                                    <Link
                                                                                        href={`/operations/shifts/${s.id}`}
                                                                                    >
                                                                                        Review
                                                                                    </Link>
                                                                                </Button>
                                                                            </div>
                                                                        ),
                                                                    )}
                                                                </div>
                                                            </div>
                                                        ) : null}

                                                        {/* Clean state */}
                                                        {props.eligibilityAlerts
                                                            .counts.blocked ===
                                                            0 &&
                                                        props.eligibilityAlerts
                                                            .counts.warnings ===
                                                            0 ? (
                                                            <div className="flex items-center gap-2 rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                                                                <CheckCircle2 className="size-4 text-status-success" />
                                                                All upcoming
                                                                shifts have
                                                                eligible staff
                                                                assigned.
                                                            </div>
                                                        ) : null}
                                                    </CardContent>
                                                </Card>
                                            ) : null}

                                            <Card
                                                className={
                                                    props.replacementQueue
                                                        .length > 0
                                                        ? 'border-status-warning/20'
                                                        : ''
                                                }
                                            >
                                                <CardHeader className="pb-2">
                                                    <CardTitle className="text-base">
                                                        Replacement queue
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent className="space-y-3">
                                                    <div className="flex items-center justify-between">
                                                        <div className="text-sm font-medium">
                                                            Active replacement
                                                            requests
                                                        </div>
                                                        <Badge
                                                            variant={
                                                                props
                                                                    .replacementQueue
                                                                    .length > 0
                                                                    ? 'default'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {
                                                                props
                                                                    .replacementQueue
                                                                    .length
                                                            }
                                                        </Badge>
                                                    </div>
                                                    {props.replacementQueue
                                                        .length === 0 ? (
                                                        <div className="text-sm text-muted-foreground">
                                                            No active
                                                            replacement
                                                            workflows in this
                                                            week.
                                                        </div>
                                                    ) : (
                                                        <div className="space-y-2">
                                                            {props.replacementQueue
                                                                .slice(0, 6)
                                                                .map((item) => (
                                                                    <div
                                                                        key={
                                                                            item.id
                                                                        }
                                                                        className="rounded-xl border p-3"
                                                                    >
                                                                        <div className="flex items-start justify-between gap-2">
                                                                            <div>
                                                                                <div className="text-sm font-medium">
                                                                                    {item.client ??
                                                                                        clientSingular}
                                                                                </div>
                                                                                <div className="mt-1 text-xs text-muted-foreground">
                                                                                    {item.starts_at
                                                                                        ? `${new Date(item.starts_at).toLocaleDateString()} ${fmtTime(item.starts_at)}`
                                                                                        : 'Shift time pending'}
                                                                                    {item.ends_at
                                                                                        ? `–${fmtTime(item.ends_at)}`
                                                                                        : ''}
                                                                                    {item.location
                                                                                        ? ` · ${item.location}`
                                                                                        : ''}
                                                                                </div>
                                                                            </div>
                                                                            <Badge
                                                                                variant={replacementBadgeVariant(
                                                                                    item.status,
                                                                                )}
                                                                                className="capitalize"
                                                                            >
                                                                                {
                                                                                    item.status
                                                                                }
                                                                            </Badge>
                                                                        </div>

                                                                        <div className="mt-2 text-xs text-foreground">
                                                                            {
                                                                                item.reason
                                                                            }
                                                                        </div>

                                                                        <div className="mt-2 flex flex-wrap gap-1.5">
                                                                            {item.current_staff ? (
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="text-[10px]"
                                                                                >
                                                                                    Current:{' '}
                                                                                    {
                                                                                        item.current_staff
                                                                                    }
                                                                                </Badge>
                                                                            ) : null}
                                                                            {item.open_position_status ? (
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="text-[10px] capitalize"
                                                                                >
                                                                                    Job
                                                                                    board:{' '}
                                                                                    {
                                                                                        item.open_position_status
                                                                                    }
                                                                                </Badge>
                                                                            ) : null}
                                                                            {item.open_position_claimed_by ? (
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="text-[10px]"
                                                                                >
                                                                                    Claimed
                                                                                    by{' '}
                                                                                    {
                                                                                        item.open_position_claimed_by
                                                                                    }
                                                                                </Badge>
                                                                            ) : null}
                                                                        </div>

                                                                        <div className="mt-3 flex flex-wrap gap-2">
                                                                            <Link
                                                                                href={`/operations/shifts/${item.shift_id}`}
                                                                            >
                                                                                <Button
                                                                                    size="sm"
                                                                                    variant="outline"
                                                                                >
                                                                                    Open
                                                                                    shift
                                                                                </Button>
                                                                            </Link>
                                                                            {item.open_position_id ? (
                                                                                <Link href="/operations/job-board">
                                                                                    <Button
                                                                                        size="sm"
                                                                                        variant="outline"
                                                                                    >
                                                                                        Job
                                                                                        board
                                                                                    </Button>
                                                                                </Link>
                                                                            ) : null}
                                                                            {props.canManageAny &&
                                                                            item.status ===
                                                                                'claimed' &&
                                                                            item.open_position_id ? (
                                                                                <Button
                                                                                    size="sm"
                                                                                    onClick={() =>
                                                                                        router.post(
                                                                                            `/operations/job-board/${item.open_position_id}/approve`,
                                                                                            {},
                                                                                            {
                                                                                                preserveScroll: true,
                                                                                            },
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    Approve
                                                                                    claim
                                                                                </Button>
                                                                            ) : null}
                                                                            {props.canManageAny &&
                                                                            [
                                                                                'requested',
                                                                                'claimed',
                                                                            ].includes(
                                                                                item.status,
                                                                            ) ? (
                                                                                <Button
                                                                                    size="sm"
                                                                                    variant="ghost"
                                                                                    onClick={() =>
                                                                                        router.patch(
                                                                                            `/operations/shifts/${item.shift_id}/replacement-request/cancel`,
                                                                                            {},
                                                                                            {
                                                                                                preserveScroll: true,
                                                                                            },
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    Cancel
                                                                                </Button>
                                                                            ) : null}
                                                                        </div>
                                                                    </div>
                                                                ))}
                                                        </div>
                                                    )}
                                                </CardContent>
                                            </Card>

                                            <Card>
                                                <CardHeader className="pb-2">
                                                    <CardTitle className="text-base">
                                                        Recurring patterns
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent className="space-y-3">
                                                    <div className="flex items-center justify-between">
                                                        <div className="text-sm font-medium">
                                                            Series active this
                                                            week
                                                        </div>
                                                        <Badge
                                                            variant={
                                                                props
                                                                    .recurringPatterns
                                                                    .length > 0
                                                                    ? 'secondary'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {
                                                                props
                                                                    .recurringPatterns
                                                                    .length
                                                            }
                                                        </Badge>
                                                    </div>
                                                    {props.recurringPatterns
                                                        .length === 0 ? (
                                                        <div className="text-sm text-muted-foreground">
                                                            No recurring roster
                                                            patterns in this
                                                            week.
                                                        </div>
                                                    ) : (
                                                        <div className="space-y-2">
                                                            {props.recurringPatterns
                                                                .slice(0, 6)
                                                                .map(
                                                                    (
                                                                        pattern,
                                                                    ) => (
                                                                        <div
                                                                            key={
                                                                                pattern.id
                                                                            }
                                                                            className="rounded-xl border p-3"
                                                                        >
                                                                            <div className="flex items-start justify-between gap-2">
                                                                                <div>
                                                                                    <div className="text-sm font-medium">
                                                                                        {pattern.client ??
                                                                                            clientSingular}
                                                                                    </div>
                                                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                                                        {pattern.weekdays
                                                                                            .map(
                                                                                                weekdayLabel,
                                                                                            )
                                                                                            .join(
                                                                                                ', ',
                                                                                            )}
                                                                                        {pattern.starts_time &&
                                                                                        pattern.ends_time
                                                                                            ? ` · ${seriesTimeLabel(pattern.starts_time, pattern.ends_time)}`
                                                                                            : ''}
                                                                                    </div>
                                                                                </div>
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="capitalize"
                                                                                >
                                                                                    {shiftTypeLabel(
                                                                                        pattern.shift_type,
                                                                                    )}
                                                                                </Badge>
                                                                            </div>

                                                                            <div className="mt-2 flex flex-wrap gap-1.5">
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="text-[10px]"
                                                                                >
                                                                                    {
                                                                                        pattern.occurrences_this_week
                                                                                    }{' '}
                                                                                    this
                                                                                    week
                                                                                </Badge>
                                                                                {pattern.open_occurrences >
                                                                                0 ? (
                                                                                    <Badge
                                                                                        variant="default"
                                                                                        className="text-[10px]"
                                                                                    >
                                                                                        {
                                                                                            pattern.open_occurrences
                                                                                        }{' '}
                                                                                        open
                                                                                    </Badge>
                                                                                ) : null}
                                                                                {pattern.active_replacement_count >
                                                                                0 ? (
                                                                                    <Badge
                                                                                        variant="secondary"
                                                                                        className="text-[10px]"
                                                                                    >
                                                                                        {
                                                                                            pattern.active_replacement_count
                                                                                        }{' '}
                                                                                        replacement
                                                                                    </Badge>
                                                                                ) : null}
                                                                                {pattern.is_sleepover ? (
                                                                                    <Badge
                                                                                        variant="outline"
                                                                                        className="text-[10px]"
                                                                                    >
                                                                                        Sleepover
                                                                                    </Badge>
                                                                                ) : null}
                                                                                {pattern.is_on_call ? (
                                                                                    <Badge
                                                                                        variant="outline"
                                                                                        className="text-[10px]"
                                                                                    >
                                                                                        On-call
                                                                                    </Badge>
                                                                                ) : null}
                                                                            </div>

                                                                            <div className="mt-2 text-xs text-muted-foreground">
                                                                                {pattern.service_context
                                                                                    ? `${pattern.service_context} · `
                                                                                    : ''}
                                                                                {pattern.staff
                                                                                    ? `Primary staff: ${pattern.staff}`
                                                                                    : 'Open recurring pattern'}
                                                                                {pattern.location
                                                                                    ? ` · ${pattern.location}`
                                                                                    : ''}
                                                                            </div>

                                                                            <div className="mt-3 flex flex-wrap gap-2">
                                                                                <Link
                                                                                    href={`/operations/shifts/series/${pattern.id}`}
                                                                                >
                                                                                    <Button
                                                                                        size="sm"
                                                                                        variant="outline"
                                                                                    >
                                                                                        View
                                                                                        series
                                                                                    </Button>
                                                                                </Link>
                                                                                {pattern.next_shift_id ? (
                                                                                    <>
                                                                                        <Link
                                                                                            href={`/operations/shifts/${pattern.next_shift_id}`}
                                                                                        >
                                                                                            <Button
                                                                                                size="sm"
                                                                                                variant="outline"
                                                                                            >
                                                                                                Open
                                                                                                next
                                                                                                shift
                                                                                            </Button>
                                                                                        </Link>
                                                                                        <Link
                                                                                            href={`/operations/shifts/${pattern.next_shift_id}/edit`}
                                                                                        >
                                                                                            <Button size="sm">
                                                                                                Edit
                                                                                                future
                                                                                            </Button>
                                                                                        </Link>
                                                                                    </>
                                                                                ) : null}
                                                                            </div>
                                                                        </div>
                                                                    ),
                                                                )}
                                                        </div>
                                                    )}
                                                </CardContent>
                                            </Card>

                                            <Card>
                                                <CardHeader className="pb-2">
                                                    <CardTitle className="text-base">
                                                        Payroll & compliance
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent className="space-y-3">
                                                    <div className="flex items-center justify-between">
                                                        <div className="text-sm font-medium">
                                                            Timesheets needing
                                                            attention
                                                        </div>
                                                        <Badge
                                                            variant={
                                                                timesheetsNeedingAttention.length >
                                                                0
                                                                    ? 'default'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {
                                                                timesheetsNeedingAttention.length
                                                            }
                                                        </Badge>
                                                    </div>
                                                    {timesheetsNeedingAttention.length ===
                                                    0 ? (
                                                        <div className="text-sm text-muted-foreground">
                                                            Nothing to action in
                                                            this week.
                                                        </div>
                                                    ) : (
                                                        <div className="space-y-2">
                                                            {timesheetsNeedingAttention
                                                                .slice(0, 6)
                                                                .map((sh) => (
                                                                    <Link
                                                                        key={
                                                                            sh.id
                                                                        }
                                                                        href={`/operations/shifts/${sh.id}`}
                                                                        className="block"
                                                                    >
                                                                        <div className="rounded-md border p-2 hover:bg-muted">
                                                                            <div className="flex items-start justify-between gap-2">
                                                                                <div className="text-xs font-medium">
                                                                                    {new Date(
                                                                                        sh.starts_at,
                                                                                    ).toLocaleDateString()}{' '}
                                                                                    {fmtTime(
                                                                                        sh.starts_at,
                                                                                    )}

                                                                                    –
                                                                                    {fmtTime(
                                                                                        sh.ends_at,
                                                                                    )}
                                                                                </div>
                                                                                {sh.timesheet_status ? (
                                                                                    <Badge
                                                                                        variant="outline"
                                                                                        className="text-[10px]"
                                                                                    >
                                                                                        TS:{' '}
                                                                                        {
                                                                                            sh.timesheet_status
                                                                                        }
                                                                                    </Badge>
                                                                                ) : null}
                                                                            </div>
                                                                            <div className="mt-1 text-xs text-foreground">
                                                                                {sh.client ??
                                                                                    clientSingular}{' '}
                                                                                ·{' '}
                                                                                {sh.staff ??
                                                                                    'Staff'}
                                                                            </div>
                                                                        </div>
                                                                    </Link>
                                                                ))}
                                                        </div>
                                                    )}
                                                    <div>
                                                        <Link href="/operations/timesheets">
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                            >
                                                                Open Timesheets
                                                            </Button>
                                                        </Link>
                                                    </div>
                                                </CardContent>
                                            </Card>

                                            <Card>
                                                <CardHeader className="pb-2">
                                                    <CardTitle className="text-base">
                                                        Safety signals
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent className="space-y-3">
                                                    <div className="flex items-center justify-between">
                                                        <div className="text-sm font-medium">
                                                            Shifts with
                                                            incidents
                                                        </div>
                                                        <Badge
                                                            variant={
                                                                shiftsWithIncidents.length >
                                                                0
                                                                    ? 'destructive'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {
                                                                shiftsWithIncidents.length
                                                            }
                                                        </Badge>
                                                    </div>
                                                    {shiftsWithIncidents.length ===
                                                    0 ? (
                                                        <div className="text-sm text-muted-foreground">
                                                            No incidents linked
                                                            to shifts in this
                                                            week.
                                                        </div>
                                                    ) : (
                                                        <div className="space-y-2">
                                                            {shiftsWithIncidents
                                                                .slice(0, 6)
                                                                .map((sh) => (
                                                                    <Link
                                                                        key={
                                                                            sh.id
                                                                        }
                                                                        href={`/operations/shifts/${sh.id}`}
                                                                        className="block"
                                                                    >
                                                                        <div className="rounded-md border p-2 hover:bg-muted">
                                                                            <div className="flex items-start justify-between gap-2">
                                                                                <div className="text-xs font-medium">
                                                                                    {new Date(
                                                                                        sh.starts_at,
                                                                                    ).toLocaleDateString()}{' '}
                                                                                    {fmtTime(
                                                                                        sh.starts_at,
                                                                                    )}

                                                                                    –
                                                                                    {fmtTime(
                                                                                        sh.ends_at,
                                                                                    )}
                                                                                </div>
                                                                                <Badge
                                                                                    variant="destructive"
                                                                                    className="text-[10px]"
                                                                                >
                                                                                    {
                                                                                        sh.incidents_count
                                                                                    }{' '}
                                                                                    incident
                                                                                    {sh.incidents_count ===
                                                                                    1
                                                                                        ? ''
                                                                                        : 's'}
                                                                                </Badge>
                                                                            </div>
                                                                            <div className="mt-1 text-xs text-foreground">
                                                                                {sh.client ??
                                                                                    clientSingular}{' '}
                                                                                ·{' '}
                                                                                {sh.staff ??
                                                                                    'Staff'}
                                                                            </div>
                                                                        </div>
                                                                    </Link>
                                                                ))}
                                                        </div>
                                                    )}
                                                    <div>
                                                        <Link href="/incidents">
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                            >
                                                                Open Incidents
                                                            </Button>
                                                        </Link>
                                                    </div>
                                                </CardContent>
                                            </Card>

                                            <Card>
                                                <CardHeader className="pb-2">
                                                    <CardTitle className="text-base">
                                                        Capacity
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent className="space-y-2">
                                                    {props.capacity.filter(
                                                        (c) => c.warn,
                                                    ).length === 0 ? (
                                                        <div className="text-sm text-muted-foreground">
                                                            No capacity warnings
                                                            this week.
                                                        </div>
                                                    ) : (
                                                        <div className="space-y-2">
                                                            {props.capacity
                                                                .filter(
                                                                    (c) =>
                                                                        c.warn,
                                                                )
                                                                .slice(0, 8)
                                                                .map((c) => (
                                                                    <div
                                                                        key={
                                                                            c.user_id
                                                                        }
                                                                        className="flex items-center justify-between rounded-md border p-2"
                                                                    >
                                                                        <div className="text-sm">
                                                                            {
                                                                                c.name
                                                                            }
                                                                        </div>
                                                                        <Badge
                                                                            variant={
                                                                                c.warn ===
                                                                                'high'
                                                                                    ? 'destructive'
                                                                                    : 'default'
                                                                            }
                                                                        >
                                                                            {c.hours.toFixed(
                                                                                1,
                                                                            )}
                                                                            h
                                                                        </Badge>
                                                                    </div>
                                                                ))}
                                                        </div>
                                                    )}
                                                </CardContent>
                                            </Card>
                                        </div>
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
                                        <CardTitle className="text-base">
                                            Week roster
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {props.canManageAny ? (
                                            <div className="overflow-x-auto">
                                                <table className="w-full min-w-[900px] border-collapse">
                                                    <thead>
                                                        <tr className="border-b">
                                                            <th className="w-48 px-2 py-2 text-left text-xs font-medium text-muted-foreground">
                                                                Staff
                                                            </th>
                                                            {days.map((d) => (
                                                                <th
                                                                    key={ymd(d)}
                                                                    className="px-2 py-2 text-left text-xs font-medium text-muted-foreground"
                                                                >
                                                                    {fmtDay(d)}
                                                                </th>
                                                            ))}
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {props.staff.map(
                                                            (s) => (
                                                                <tr
                                                                    key={s.id}
                                                                    className="border-b align-top"
                                                                >
                                                                    <td className="px-2 py-3 text-sm font-medium">
                                                                        {s.name}
                                                                    </td>
                                                                    {days.map(
                                                                        (d) => {
                                                                            const key = `${s.id}-${ymd(d)}`;
                                                                            const items =
                                                                                shiftsByStaffDay.get(
                                                                                    key,
                                                                                ) ??
                                                                                [];
                                                                            return (
                                                                                <td
                                                                                    key={
                                                                                        key
                                                                                    }
                                                                                    className="px-2 py-2"
                                                                                >
                                                                                    <div className="space-y-2">
                                                                                        {items.length ===
                                                                                        0 ? (
                                                                                            <div className="text-xs text-muted-foreground">
                                                                                                —
                                                                                            </div>
                                                                                        ) : (
                                                                                            items.map(
                                                                                                (
                                                                                                    sh,
                                                                                                ) => (
                                                                                                    <Link
                                                                                                        key={
                                                                                                            sh.id
                                                                                                        }
                                                                                                        href={`/operations/shifts/${sh.id}`}
                                                                                                        className="block"
                                                                                                    >
                                                                                                        <div className="rounded-md border p-2 hover:bg-muted">
                                                                                                            <div className="flex items-start justify-between gap-2">
                                                                                                                <div className="text-xs font-medium">
                                                                                                                    {fmtTime(
                                                                                                                        sh.starts_at,
                                                                                                                    )}

                                                                                                                    –
                                                                                                                    {fmtTime(
                                                                                                                        sh.ends_at,
                                                                                                                    )}
                                                                                                                </div>
                                                                                                                <Badge
                                                                                                                    variant={statusBadgeVariant(
                                                                                                                        sh.status,
                                                                                                                    )}
                                                                                                                    className="text-[10px]"
                                                                                                                >
                                                                                                                    {
                                                                                                                        sh.status
                                                                                                                    }
                                                                                                                </Badge>
                                                                                                            </div>
                                                                                                            <div className="mt-1 text-xs text-foreground">
                                                                                                                {sh.client ??
                                                                                                                    clientSingular}
                                                                                                            </div>

                                                                                                            <div className="mt-1 flex flex-wrap gap-1">
                                                                                                                {sh.shift_series_id ? (
                                                                                                                    <Badge
                                                                                                                        variant="outline"
                                                                                                                        className="text-[10px]"
                                                                                                                    >
                                                                                                                        Recurring
                                                                                                                    </Badge>
                                                                                                                ) : null}
                                                                                                                {sh.has_active_replacement ? (
                                                                                                                    <Badge
                                                                                                                        variant={replacementBadgeVariant(
                                                                                                                            sh.replacement_status,
                                                                                                                        )}
                                                                                                                        className="text-[10px]"
                                                                                                                    >
                                                                                                                        Replacement
                                                                                                                    </Badge>
                                                                                                                ) : null}
                                                                                                                {sh.incidents_count >
                                                                                                                    0 && (
                                                                                                                    <Badge
                                                                                                                        variant="destructive"
                                                                                                                        className="text-[10px]"
                                                                                                                    >
                                                                                                                        {
                                                                                                                            sh.incidents_count
                                                                                                                        }{' '}
                                                                                                                        incident
                                                                                                                        {sh.incidents_count ===
                                                                                                                        1
                                                                                                                            ? ''
                                                                                                                            : 's'}
                                                                                                                    </Badge>
                                                                                                                )}
                                                                                                                {sh.tasks_total >
                                                                                                                    0 && (
                                                                                                                    <Badge
                                                                                                                        variant="outline"
                                                                                                                        className="text-[10px]"
                                                                                                                    >
                                                                                                                        Tasks:{' '}
                                                                                                                        {
                                                                                                                            sh.tasks_completed
                                                                                                                        }

                                                                                                                        /
                                                                                                                        {
                                                                                                                            sh.tasks_total
                                                                                                                        }
                                                                                                                    </Badge>
                                                                                                                )}
                                                                                                                {sh.timesheet_status && (
                                                                                                                    <Badge
                                                                                                                        variant="outline"
                                                                                                                        className="text-[10px]"
                                                                                                                    >
                                                                                                                        TS:{' '}
                                                                                                                        {
                                                                                                                            sh.timesheet_status
                                                                                                                        }
                                                                                                                    </Badge>
                                                                                                                )}
                                                                                                            </div>
                                                                                                        </div>
                                                                                                    </Link>
                                                                                                ),
                                                                                            )
                                                                                        )}
                                                                                    </div>
                                                                                </td>
                                                                            );
                                                                        },
                                                                    )}
                                                                </tr>
                                                            ),
                                                        )}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ) : (
                                            <div className="space-y-4">
                                                {days.map((d) => {
                                                    const key = ymd(d);
                                                    const items =
                                                        shiftsByDay.get(key) ??
                                                        [];
                                                    return (
                                                        <div key={key}>
                                                            <div className="mb-2 text-sm font-medium">
                                                                {fmtDay(d)}
                                                            </div>
                                                            <div className="space-y-2">
                                                                {items.length ===
                                                                0 ? (
                                                                    <div className="text-sm text-muted-foreground">
                                                                        No
                                                                        shifts.
                                                                    </div>
                                                                ) : (
                                                                    items.map(
                                                                        (
                                                                            sh,
                                                                        ) => (
                                                                            <Link
                                                                                key={
                                                                                    sh.id
                                                                                }
                                                                                href={`/operations/shifts/${sh.id}`}
                                                                                className="block"
                                                                            >
                                                                                <div className="rounded-md border p-3 hover:bg-muted">
                                                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                                                        <div className="text-sm font-medium">
                                                                                            {fmtTime(
                                                                                                sh.starts_at,
                                                                                            )}

                                                                                            –
                                                                                            {fmtTime(
                                                                                                sh.ends_at,
                                                                                            )}{' '}
                                                                                            ·{' '}
                                                                                            {
                                                                                                sh.client
                                                                                            }
                                                                                        </div>
                                                                                        <Badge
                                                                                            variant={statusBadgeVariant(
                                                                                                sh.status,
                                                                                            )}
                                                                                        >
                                                                                            {
                                                                                                sh.status
                                                                                            }
                                                                                        </Badge>
                                                                                    </div>
                                                                                    <div className="mt-2 flex flex-wrap gap-2">
                                                                                        {sh.shift_series_id ? (
                                                                                            <Badge variant="outline">
                                                                                                Recurring
                                                                                            </Badge>
                                                                                        ) : null}
                                                                                        {sh.has_active_replacement ? (
                                                                                            <Badge
                                                                                                variant={replacementBadgeVariant(
                                                                                                    sh.replacement_status,
                                                                                                )}
                                                                                            >
                                                                                                Replacement
                                                                                            </Badge>
                                                                                        ) : null}
                                                                                        {sh.tasks_total >
                                                                                            0 && (
                                                                                            <Badge variant="outline">
                                                                                                Tasks:{' '}
                                                                                                {
                                                                                                    sh.tasks_completed
                                                                                                }

                                                                                                /
                                                                                                {
                                                                                                    sh.tasks_total
                                                                                                }
                                                                                            </Badge>
                                                                                        )}
                                                                                        {sh.incidents_count >
                                                                                            0 && (
                                                                                            <Badge variant="destructive">
                                                                                                {
                                                                                                    sh.incidents_count
                                                                                                }{' '}
                                                                                                incident
                                                                                                {sh.incidents_count ===
                                                                                                1
                                                                                                    ? ''
                                                                                                    : 's'}
                                                                                            </Badge>
                                                                                        )}
                                                                                        {sh.timesheet_status && (
                                                                                            <Badge variant="outline">
                                                                                                Timesheet:{' '}
                                                                                                {
                                                                                                    sh.timesheet_status
                                                                                                }
                                                                                            </Badge>
                                                                                        )}
                                                                                    </div>
                                                                                </div>
                                                                            </Link>
                                                                        ),
                                                                    )
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
                                    {props.stats.open > 0 ? (
                                        <Badge
                                            variant="default"
                                            className="text-[10px]"
                                        >
                                            {props.stats.open}
                                        </Badge>
                                    ) : null}
                                </span>
                            ),
                            content: (
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base">
                                            Open / unassigned shifts
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        {!props.canManageAny ? (
                                            <div className="text-sm text-muted-foreground">
                                                Only managers can assign open
                                                shifts.
                                            </div>
                                        ) : null}

                                        {props.shifts.filter(
                                            (s) => s.user_id === null,
                                        ).length === 0 ? (
                                            <div className="text-sm text-muted-foreground">
                                                No open shifts in this week.
                                            </div>
                                        ) : (
                                            <div className="space-y-2">
                                                {props.shifts
                                                    .filter(
                                                        (s) =>
                                                            s.user_id === null,
                                                    )
                                                    .map((sh) => (
                                                        <div
                                                            key={sh.id}
                                                            className="rounded-md border p-3"
                                                        >
                                                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                                                <div>
                                                                    <div className="text-sm font-medium">
                                                                        {sh.client ??
                                                                            clientSingular}{' '}
                                                                        ·{' '}
                                                                        {new Date(
                                                                            sh.starts_at,
                                                                        ).toLocaleDateString()}{' '}
                                                                        {fmtTime(
                                                                            sh.starts_at,
                                                                        )}
                                                                        –
                                                                        {fmtTime(
                                                                            sh.ends_at,
                                                                        )}
                                                                    </div>
                                                                    <div className="mt-1 flex flex-wrap gap-1.5 text-xs text-muted-foreground">
                                                                        <span>
                                                                            Status:{' '}
                                                                            {
                                                                                sh.status
                                                                            }
                                                                            {sh.location
                                                                                ? ` · ${sh.location}`
                                                                                : ''}
                                                                        </span>
                                                                        {sh.shift_series_id ? (
                                                                            <Badge
                                                                                variant="outline"
                                                                                className="text-[10px]"
                                                                            >
                                                                                Recurring
                                                                            </Badge>
                                                                        ) : null}
                                                                        {sh.has_active_replacement ? (
                                                                            <Badge
                                                                                variant={replacementBadgeVariant(
                                                                                    sh.replacement_status,
                                                                                )}
                                                                                className="text-[10px]"
                                                                            >
                                                                                Replacement
                                                                            </Badge>
                                                                        ) : null}
                                                                    </div>
                                                                </div>
                                                                {props.canManageAny ? (
                                                                    <div className="flex items-center gap-2">
                                                                        <Select
                                                                            value={
                                                                                assignForm
                                                                                    .data
                                                                                    .user_id
                                                                            }
                                                                            onValueChange={(
                                                                                v,
                                                                            ) =>
                                                                                assignForm.setData(
                                                                                    'user_id',
                                                                                    v,
                                                                                )
                                                                            }
                                                                        >
                                                                            <SelectTrigger className="w-[220px]">
                                                                                <SelectValue placeholder="Assign staff" />
                                                                            </SelectTrigger>
                                                                            <SelectContent>
                                                                                {availableStaffForShift(
                                                                                    sh,
                                                                                ).map(
                                                                                    (
                                                                                        s,
                                                                                    ) => (
                                                                                        <SelectItem
                                                                                            key={
                                                                                                s.id
                                                                                            }
                                                                                            value={String(
                                                                                                s.id,
                                                                                            )}
                                                                                        >
                                                                                            {
                                                                                                s.name
                                                                                            }
                                                                                        </SelectItem>
                                                                                    ),
                                                                                )}
                                                                            </SelectContent>
                                                                        </Select>
                                                                        <Button
                                                                            size="sm"
                                                                            disabled={
                                                                                assignForm.processing ||
                                                                                !assignForm
                                                                                    .data
                                                                                    .user_id
                                                                            }
                                                                            onClick={() => {
                                                                                assignForm.post(
                                                                                    `/operations/shifts/${sh.id}/assign`,
                                                                                    {
                                                                                        preserveScroll: true,
                                                                                        onSuccess:
                                                                                            () =>
                                                                                                assignForm.reset(
                                                                                                    'user_id',
                                                                                                ),
                                                                                    },
                                                                                );
                                                                            }}
                                                                        >
                                                                            Assign
                                                                        </Button>
                                                                        <Link
                                                                            href={`/operations/shifts/${sh.id}`}
                                                                        >
                                                                            <Button
                                                                                size="sm"
                                                                                variant="outline"
                                                                            >
                                                                                Open
                                                                            </Button>
                                                                        </Link>
                                                                    </div>
                                                                ) : (
                                                                    <Link
                                                                        href={`/operations/shifts/${sh.id}`}
                                                                    >
                                                                        <Button
                                                                            size="sm"
                                                                            variant="outline"
                                                                        >
                                                                            Open
                                                                        </Button>
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
                                    {props.stats.time_off_conflicts > 0 ? (
                                        <Badge
                                            variant="destructive"
                                            className="text-[10px]"
                                        >
                                            conflicts
                                        </Badge>
                                    ) : null}
                                </span>
                            ),
                            content: (
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base">
                                            Leave / unavailability (one-off)
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <form
                                            className="space-y-3 rounded-md border p-3"
                                            onSubmit={(e) => {
                                                e.preventDefault();
                                                // NOTE: Inertia's useForm().transform() does not reliably return the form object
                                                // across versions, so do not chain .transform().post().
                                                timeOffForm.transform(
                                                    (d: any) => ({
                                                        ...d,
                                                        return_to:
                                                            '/operations/rostering',
                                                        user_id:
                                                            d.user_id === 'self'
                                                                ? undefined
                                                                : Number(
                                                                      d.user_id,
                                                                  ),
                                                    }),
                                                );

                                                timeOffForm.post(
                                                    storeTimeOff.url(),
                                                    {
                                                        preserveScroll: true,
                                                        onFinish: () =>
                                                            timeOffForm.transform(
                                                                (d: any) => d,
                                                            ),
                                                    },
                                                );
                                            }}
                                        >
                                            <div className="font-medium">
                                                Add time-off block
                                            </div>
                                            <div className="grid gap-3 md:grid-cols-4">
                                                {props.canManageAny ? (
                                                    <div className="space-y-1">
                                                        <Label>Staff</Label>
                                                        <Select
                                                            value={
                                                                timeOffForm.data
                                                                    .user_id
                                                            }
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                timeOffForm.setData(
                                                                    'user_id',
                                                                    v,
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue placeholder="Select staff" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="self">
                                                                    (Me)
                                                                </SelectItem>
                                                                {props.staff.map(
                                                                    (s) => (
                                                                        <SelectItem
                                                                            key={
                                                                                s.id
                                                                            }
                                                                            value={String(
                                                                                s.id,
                                                                            )}
                                                                        >
                                                                            {
                                                                                s.name
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                ) : null}

                                                <div className="space-y-1">
                                                    <Label>Type</Label>
                                                    <Select
                                                        value={
                                                            timeOffForm.data
                                                                .type
                                                        }
                                                        onValueChange={(v) =>
                                                            timeOffForm.setData(
                                                                'type',
                                                                v,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Type" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="leave">
                                                                Leave
                                                            </SelectItem>
                                                            <SelectItem value="unavailable">
                                                                Unavailable
                                                            </SelectItem>
                                                            <SelectItem value="training">
                                                                Training
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="space-y-1">
                                                    <Label>Start</Label>
                                                    <Input
                                                        type="datetime-local"
                                                        value={
                                                            timeOffForm.data
                                                                .starts_at
                                                        }
                                                        onChange={(e) =>
                                                            timeOffForm.setData(
                                                                'starts_at',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </div>

                                                <div className="space-y-1">
                                                    <Label>End</Label>
                                                    <Input
                                                        type="datetime-local"
                                                        value={
                                                            timeOffForm.data
                                                                .ends_at
                                                        }
                                                        onChange={(e) =>
                                                            timeOffForm.setData(
                                                                'ends_at',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </div>

                                            <div className="grid gap-3 md:grid-cols-2">
                                                <div className="space-y-1">
                                                    <Label>Label</Label>
                                                    <Input
                                                        value={
                                                            timeOffForm.data
                                                                .label
                                                        }
                                                        onChange={(e) =>
                                                            timeOffForm.setData(
                                                                'label',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="e.g. Annual leave"
                                                    />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Notes</Label>
                                                    <Input
                                                        value={
                                                            timeOffForm.data
                                                                .notes
                                                        }
                                                        onChange={(e) =>
                                                            timeOffForm.setData(
                                                                'notes',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="Optional"
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={
                                                        timeOffForm.processing
                                                    }
                                                >
                                                    Save
                                                </Button>
                                                {timeOffForm.recentlySuccessful ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        Saved.
                                                    </span>
                                                ) : null}
                                            </div>
                                        </form>

                                        <div className="space-y-2">
                                            {props.timeOffs.length === 0 ? (
                                                <div className="text-sm text-muted-foreground">
                                                    No time-off blocks in this
                                                    week.
                                                </div>
                                            ) : (
                                                props.timeOffs.map((b) => (
                                                    <div
                                                        key={b.id}
                                                        className="flex flex-col gap-2 rounded-md border p-3 md:flex-row md:items-center md:justify-between"
                                                    >
                                                        <div>
                                                            <div className="text-sm font-medium">
                                                                {props.canManageAny
                                                                    ? (b.user ??
                                                                      'Staff')
                                                                    : 'Me'}{' '}
                                                                ·{' '}
                                                                {new Date(
                                                                    b.starts_at,
                                                                ).toLocaleDateString()}{' '}
                                                                {fmtTime(
                                                                    b.starts_at,
                                                                )}
                                                                –
                                                                {fmtTime(
                                                                    b.ends_at,
                                                                )}
                                                            </div>
                                                            <div className="mt-1 text-xs text-muted-foreground">
                                                                <span className="capitalize">
                                                                    {b.type}
                                                                </span>
                                                                {b.label
                                                                    ? ` · ${b.label}`
                                                                    : ''}
                                                                {b.notes
                                                                    ? ` · ${b.notes}`
                                                                    : ''}
                                                            </div>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <Badge
                                                                variant="outline"
                                                                className="capitalize"
                                                            >
                                                                {b.type}
                                                            </Badge>
                                                            <Button
                                                                size="sm"
                                                                variant="destructive"
                                                                onClick={() => {
                                                                    if (
                                                                        !confirm(
                                                                            'Delete this time-off block?',
                                                                        )
                                                                    )
                                                                        return;
                                                                    router.delete(
                                                                        destroyTimeOff.url(
                                                                            b.id,
                                                                        ),
                                                                        {
                                                                            preserveScroll: true,
                                                                            data: {
                                                                                return_to:
                                                                                    '/operations/rostering',
                                                                            },
                                                                        },
                                                                    );
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
                                        <CardTitle className="text-base">
                                            Weekly capacity
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {!props.canManageAny ? (
                                            <div className="text-sm text-muted-foreground">
                                                Capacity is available for
                                                managers.
                                            </div>
                                        ) : (
                                            <div className="overflow-x-auto">
                                                <table className="w-full min-w-[500px] border-collapse">
                                                    <thead>
                                                        <tr className="border-b">
                                                            <th className="px-2 py-2 text-left text-xs font-medium text-muted-foreground">
                                                                Staff
                                                            </th>
                                                            <th className="px-2 py-2 text-left text-xs font-medium text-muted-foreground">
                                                                Hours
                                                            </th>
                                                            <th className="px-2 py-2 text-left text-xs font-medium text-muted-foreground">
                                                                Signal
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {props.capacity.map(
                                                            (c) => (
                                                                <tr
                                                                    key={
                                                                        c.user_id
                                                                    }
                                                                    className="border-b"
                                                                >
                                                                    <td className="px-2 py-2 text-sm font-medium">
                                                                        {c.name}
                                                                    </td>
                                                                    <td className="px-2 py-2 text-sm">
                                                                        {
                                                                            c.hours
                                                                        }
                                                                    </td>
                                                                    <td className="px-2 py-2">
                                                                        {c.warn ===
                                                                        'high' ? (
                                                                            <Badge variant="destructive">
                                                                                High
                                                                            </Badge>
                                                                        ) : c.warn ===
                                                                          'medium' ? (
                                                                            <Badge variant="default">
                                                                                Watch
                                                                            </Badge>
                                                                        ) : (
                                                                            <Badge variant="outline">
                                                                                OK
                                                                            </Badge>
                                                                        )}
                                                                    </td>
                                                                </tr>
                                                            ),
                                                        )}
                                                    </tbody>
                                                </table>
                                                <div className="mt-2 text-xs text-muted-foreground">
                                                    Signals: Watch at ≥40h, High
                                                    at ≥50h for the roster week.
                                                </div>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ),
                        },
                        {
                            key: 'heatmap',
                            label: 'Heatmap',
                            content: (
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center justify-between text-base">
                                            <span>
                                                Coverage Heatmap — 24-Hour View
                                            </span>
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    size="sm"
                                                    variant={
                                                        coverageMode ===
                                                        'understaffed'
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    onClick={() =>
                                                        setCoverageMode(
                                                            'understaffed',
                                                        )
                                                    }
                                                >
                                                    Understaffed
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant={
                                                        coverageMode ===
                                                        'assigned'
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    onClick={() =>
                                                        setCoverageMode(
                                                            'assigned',
                                                        )
                                                    }
                                                >
                                                    Assigned
                                                </Button>
                                            </div>
                                        </CardTitle>
                                        <p className="text-xs text-muted-foreground">
                                            Full 24-hour hourly breakdown for
                                            the roster week. Each cell shows the
                                            number of{' '}
                                            {coverageMode === 'assigned'
                                                ? 'assigned staff'
                                                : 'open/unfilled shifts'}{' '}
                                            per hour.
                                        </p>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="overflow-x-auto">
                                            <div className="min-w-[800px]">
                                                <div className="grid grid-cols-[56px_repeat(7,1fr)] gap-px overflow-hidden rounded-lg border bg-border/50">
                                                    {/* Header row */}
                                                    <div className="bg-muted/80 px-2 py-1.5 text-[10px] font-semibold text-muted-foreground">
                                                        Hour
                                                    </div>
                                                    {days.map((d) => (
                                                        <div
                                                            key={ymd(d)}
                                                            className="bg-muted/80 py-1.5 text-center text-[10px] font-semibold"
                                                        >
                                                            {fmtDay(d)}
                                                        </div>
                                                    ))}

                                                    {/* 24 hour rows */}
                                                    {Array.from({
                                                        length: 24,
                                                    }).map((_, h) => (
                                                        <Fragment key={h}>
                                                            <div className="flex items-center bg-background px-2 text-[10px] text-muted-foreground">
                                                                {String(
                                                                    h,
                                                                ).padStart(
                                                                    2,
                                                                    '0',
                                                                )}
                                                                :00
                                                            </div>
                                                            {days.map((d) => {
                                                                const dk =
                                                                    ymd(d);
                                                                const cell =
                                                                    coverageHeatmap
                                                                        .grid[
                                                                        dk
                                                                    ]?.assigned
                                                                        ? {
                                                                              assigned:
                                                                                  coverageHeatmap
                                                                                      .grid[
                                                                                      dk
                                                                                  ]
                                                                                      .assigned[
                                                                                      h
                                                                                  ] ??
                                                                                  0,
                                                                              open:
                                                                                  coverageHeatmap
                                                                                      .grid[
                                                                                      dk
                                                                                  ]
                                                                                      .open[
                                                                                      h
                                                                                  ] ??
                                                                                  0,
                                                                          }
                                                                        : {
                                                                              assigned: 0,
                                                                              open: 0,
                                                                          };
                                                                const v =
                                                                    coverageMode ===
                                                                    'assigned'
                                                                        ? cell.assigned
                                                                        : cell.open;
                                                                const bg =
                                                                    coverageMode ===
                                                                    'assigned'
                                                                        ? v >= 3
                                                                            ? 'bg-status-info'
                                                                            : v ===
                                                                                2
                                                                              ? 'bg-status-info'
                                                                              : v ===
                                                                                  1
                                                                                ? 'bg-status-info-bg'
                                                                                : 'bg-background'
                                                                        : v >= 3
                                                                          ? 'bg-status-warning'
                                                                          : v ===
                                                                              2
                                                                            ? 'bg-status-warning'
                                                                            : v ===
                                                                                1
                                                                              ? 'bg-status-warning-bg'
                                                                              : 'bg-background';
                                                                return (
                                                                    <div
                                                                        key={`${dk}-${h}`}
                                                                        className={`flex h-6 items-center justify-center ${bg}`}
                                                                    >
                                                                        <span className="text-[10px] font-medium">
                                                                            {v >
                                                                            0
                                                                                ? v
                                                                                : ''}
                                                                        </span>
                                                                    </div>
                                                                );
                                                            })}
                                                        </Fragment>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="mt-3 flex flex-wrap gap-4 text-xs text-muted-foreground">
                                            <span className="flex items-center gap-1.5">
                                                <span className="inline-block h-3.5 w-3.5 rounded border bg-background" />{' '}
                                                No coverage
                                            </span>
                                            <span className="flex items-center gap-1.5">
                                                <span
                                                    className={`inline-block h-3.5 w-3.5 rounded ${coverageMode === 'assigned' ? 'bg-status-info-bg' : 'bg-status-warning-bg'}`}
                                                />{' '}
                                                1 staff
                                            </span>
                                            <span className="flex items-center gap-1.5">
                                                <span
                                                    className={`inline-block h-3.5 w-3.5 rounded ${coverageMode === 'assigned' ? 'bg-status-info' : 'bg-status-warning'}`}
                                                />{' '}
                                                2 staff
                                            </span>
                                            <span className="flex items-center gap-1.5">
                                                <span
                                                    className={`inline-block h-3.5 w-3.5 rounded ${coverageMode === 'assigned' ? 'bg-status-info' : 'bg-status-warning'}`}
                                                />{' '}
                                                3+ staff
                                            </span>
                                        </div>
                                    </CardContent>
                                </Card>
                            ),
                        },
                        {
                            key: 'analytics',
                            label: (
                                <span className="flex items-center gap-2">
                                    <BarChart3 className="h-3.5 w-3.5" />{' '}
                                    Analytics
                                </span>
                            ),
                            content: (
                                <div className="space-y-4">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {/* Daily Coverage Chart */}
                                        <Card>
                                            <CardHeader className="pb-3">
                                                <CardTitle className="text-base">
                                                    Daily Shift Coverage
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                {props.analytics.dailyCoverage
                                                    .length > 0 ? (
                                                    <ResponsiveContainer
                                                        width="100%"
                                                        height={220}
                                                    >
                                                        <BarChart
                                                            data={
                                                                props.analytics
                                                                    .dailyCoverage
                                                            }
                                                        >
                                                            <CartesianGrid
                                                                strokeDasharray="3 3"
                                                                className="stroke-muted"
                                                            />
                                                            <XAxis
                                                                dataKey="day"
                                                                tick={{
                                                                    fontSize: 12,
                                                                }}
                                                            />
                                                            <YAxis
                                                                tick={{
                                                                    fontSize: 12,
                                                                }}
                                                            />
                                                            <Tooltip />
                                                            <Bar
                                                                dataKey="filled"
                                                                stackId="a"
                                                                fill="#10b981"
                                                                name="Filled"
                                                                radius={[
                                                                    0, 0, 0, 0,
                                                                ]}
                                                            />
                                                            <Bar
                                                                dataKey="open"
                                                                stackId="a"
                                                                fill="#ef4444"
                                                                name="Open"
                                                                radius={[
                                                                    4, 4, 0, 0,
                                                                ]}
                                                            />
                                                            <Legend />
                                                        </BarChart>
                                                    </ResponsiveContainer>
                                                ) : (
                                                    <div className="flex h-[220px] items-center justify-center text-sm text-muted-foreground">
                                                        No shift data
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>

                                        {/* Shift Type Distribution */}
                                        <Card>
                                            <CardHeader className="pb-3">
                                                <CardTitle className="text-base">
                                                    Shift Type Distribution
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                {props.analytics
                                                    .shiftTypeDistribution
                                                    .length > 0 ? (
                                                    <ResponsiveContainer
                                                        width="100%"
                                                        height={220}
                                                    >
                                                        <PieChart>
                                                            <Pie
                                                                data={
                                                                    props
                                                                        .analytics
                                                                        .shiftTypeDistribution
                                                                }
                                                                dataKey="value"
                                                                nameKey="type"
                                                                cx="50%"
                                                                cy="50%"
                                                                outerRadius={70}
                                                                innerRadius={40}
                                                                paddingAngle={2}
                                                            >
                                                                {props.analytics.shiftTypeDistribution.map(
                                                                    (
                                                                        entry,
                                                                        i,
                                                                    ) => (
                                                                        <Cell
                                                                            key={
                                                                                entry.type
                                                                            }
                                                                            fill={
                                                                                CHART_COLORS[
                                                                                    i %
                                                                                        CHART_COLORS.length
                                                                                ]
                                                                            }
                                                                        />
                                                                    ),
                                                                )}
                                                            </Pie>
                                                            <Tooltip />
                                                            <Legend />
                                                        </PieChart>
                                                    </ResponsiveContainer>
                                                ) : (
                                                    <div className="flex h-[220px] items-center justify-center text-sm text-muted-foreground">
                                                        No shift data
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Card>
                                            <CardHeader className="pb-3">
                                                <CardTitle className="text-base">
                                                    Site Coverage Exposure
                                                </CardTitle>
                                                <p className="text-xs text-muted-foreground">
                                                    Under, exact, and
                                                    overstaffed windows by site
                                                    for the current roster
                                                    range.
                                                </p>
                                            </CardHeader>
                                            <CardContent>
                                                {siteCoverageChartData.length >
                                                0 ? (
                                                    <ResponsiveContainer
                                                        width="100%"
                                                        height={240}
                                                    >
                                                        <BarChart
                                                            data={
                                                                siteCoverageChartData
                                                            }
                                                            layout="vertical"
                                                            margin={{
                                                                left: 12,
                                                                right: 12,
                                                            }}
                                                        >
                                                            <CartesianGrid
                                                                strokeDasharray="3 3"
                                                                className="stroke-muted"
                                                            />
                                                            <XAxis
                                                                type="number"
                                                                tick={{
                                                                    fontSize: 12,
                                                                }}
                                                            />
                                                            <YAxis
                                                                type="category"
                                                                dataKey="site"
                                                                width={110}
                                                                tick={{
                                                                    fontSize: 12,
                                                                }}
                                                            />
                                                            <Tooltip />
                                                            <Legend />
                                                            <Bar
                                                                dataKey="under"
                                                                stackId="coverage"
                                                                fill="#ef4444"
                                                                name="Under-covered"
                                                                radius={[
                                                                    4, 0, 0, 4,
                                                                ]}
                                                            />
                                                            <Bar
                                                                dataKey="exact"
                                                                stackId="coverage"
                                                                fill="#10b981"
                                                                name="Exact"
                                                            />
                                                            <Bar
                                                                dataKey="over"
                                                                stackId="coverage"
                                                                fill="#f59e0b"
                                                                name="Overstaffed"
                                                                radius={[
                                                                    0, 4, 4, 0,
                                                                ]}
                                                            />
                                                        </BarChart>
                                                    </ResponsiveContainer>
                                                ) : (
                                                    <div className="flex h-[240px] items-center justify-center text-sm text-muted-foreground">
                                                        No site coverage rules
                                                        configured yet
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>

                                        <Card>
                                            <CardHeader className="pb-3">
                                                <CardTitle className="text-base">
                                                    Coverage Balance
                                                </CardTitle>
                                                <p className="text-xs text-muted-foreground">
                                                    Overall distribution of site
                                                    demand windows.
                                                </p>
                                            </CardHeader>
                                            <CardContent>
                                                {coverageBalanceData.length >
                                                0 ? (
                                                    <ResponsiveContainer
                                                        width="100%"
                                                        height={240}
                                                    >
                                                        <PieChart>
                                                            <Pie
                                                                data={
                                                                    coverageBalanceData
                                                                }
                                                                dataKey="value"
                                                                nameKey="name"
                                                                cx="50%"
                                                                cy="50%"
                                                                outerRadius={78}
                                                                innerRadius={46}
                                                                paddingAngle={3}
                                                            >
                                                                {coverageBalanceData.map(
                                                                    (entry) => (
                                                                        <Cell
                                                                            key={
                                                                                entry.name
                                                                            }
                                                                            fill={
                                                                                entry.color
                                                                            }
                                                                        />
                                                                    ),
                                                                )}
                                                            </Pie>
                                                            <Tooltip />
                                                            <Legend />
                                                        </PieChart>
                                                    </ResponsiveContainer>
                                                ) : (
                                                    <div className="flex h-[240px] items-center justify-center text-sm text-muted-foreground">
                                                        No site coverage data
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {/* 4-Week Historical Trend */}
                                        <Card>
                                            <CardHeader className="pb-3">
                                                <CardTitle className="text-base">
                                                    4-Week Trend
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                {props.analytics.historicalTrend
                                                    .length > 0 ? (
                                                    <ResponsiveContainer
                                                        width="100%"
                                                        height={200}
                                                    >
                                                        <AreaChart
                                                            data={
                                                                props.analytics
                                                                    .historicalTrend
                                                            }
                                                        >
                                                            <CartesianGrid
                                                                strokeDasharray="3 3"
                                                                className="stroke-muted"
                                                            />
                                                            <XAxis
                                                                dataKey="week"
                                                                tick={{
                                                                    fontSize: 12,
                                                                }}
                                                            />
                                                            <YAxis
                                                                tick={{
                                                                    fontSize: 12,
                                                                }}
                                                            />
                                                            <Tooltip />
                                                            <Area
                                                                type="monotone"
                                                                dataKey="completed"
                                                                stroke="#10b981"
                                                                fill="#10b981"
                                                                fillOpacity={
                                                                    0.3
                                                                }
                                                                name="Completed"
                                                            />
                                                            <Area
                                                                type="monotone"
                                                                dataKey="cancelled"
                                                                stroke="#ef4444"
                                                                fill="#ef4444"
                                                                fillOpacity={
                                                                    0.3
                                                                }
                                                                name="Cancelled"
                                                            />
                                                            <Legend />
                                                        </AreaChart>
                                                    </ResponsiveContainer>
                                                ) : (
                                                    <div className="flex h-[200px] items-center justify-center text-sm text-muted-foreground">
                                                        No historical data
                                                    </div>
                                                )}
                                            </CardContent>
                                        </Card>

                                        {/* Compliance & Leave Sidebar */}
                                        <div className="space-y-4">
                                            <Card
                                                className={
                                                    props.analytics
                                                        .complianceExpired > 0
                                                        ? 'border-status-critical/20'
                                                        : ''
                                                }
                                            >
                                                <CardHeader className="pb-3">
                                                    <CardTitle className="flex items-center gap-2 text-base">
                                                        <AlertTriangle className="h-4 w-4" />{' '}
                                                        Compliance Alerts
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent className="space-y-2">
                                                    <div className="flex items-center justify-between rounded-md border p-2 text-sm">
                                                        <span>
                                                            Expired (hard-stop)
                                                        </span>
                                                        <Badge
                                                            variant={
                                                                props.analytics
                                                                    .complianceExpired >
                                                                0
                                                                    ? 'destructive'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {
                                                                props.analytics
                                                                    .complianceExpired
                                                            }
                                                        </Badge>
                                                    </div>
                                                    <div className="flex items-center justify-between rounded-md border p-2 text-sm">
                                                        <span>
                                                            Expiring soon
                                                        </span>
                                                        <Badge
                                                            variant={
                                                                props.analytics
                                                                    .complianceExpiring >
                                                                0
                                                                    ? 'default'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {
                                                                props.analytics
                                                                    .complianceExpiring
                                                            }
                                                        </Badge>
                                                    </div>
                                                </CardContent>
                                            </Card>

                                            <Card>
                                                <CardHeader className="pb-3">
                                                    <CardTitle className="flex items-center gap-2 text-base">
                                                        <CalendarOff className="h-4 w-4" />{' '}
                                                        Leave This Week
                                                    </CardTitle>
                                                </CardHeader>
                                                <CardContent>
                                                    <p className="text-2xl font-bold">
                                                        {
                                                            props.analytics
                                                                .onLeaveCount
                                                        }
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        staff members on
                                                        approved leave
                                                    </p>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="mt-3 w-full"
                                                        asChild
                                                    >
                                                        <Link href="/hr/leave">
                                                            View Leave Dashboard
                                                        </Link>
                                                    </Button>
                                                </CardContent>
                                            </Card>
                                        </div>
                                    </div>
                                </div>
                            ),
                        },
                    ]}
                />

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">
                                Timesheets needing attention
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-muted-foreground">
                                Shifts with a linked timesheet still in
                                draft/submitted/returned.
                            </div>
                            <div className="space-y-2">
                                {props.shifts
                                    .filter((s) =>
                                        [
                                            'draft',
                                            'submitted',
                                            'returned',
                                        ].includes(s.timesheet_status ?? ''),
                                    )
                                    .slice(0, 8)
                                    .map((sh) => (
                                        <Link
                                            key={sh.id}
                                            href={`/operations/shifts/${sh.id}`}
                                            className="block"
                                        >
                                            <div className="rounded-md border p-2 hover:bg-muted">
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="text-sm font-medium">
                                                        {sh.client}
                                                    </div>
                                                    <Badge variant="outline">
                                                        TS:{' '}
                                                        {sh.timesheet_status}
                                                    </Badge>
                                                </div>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {new Date(
                                                        sh.starts_at,
                                                    ).toLocaleDateString()}{' '}
                                                    · {fmtTime(sh.starts_at)}–
                                                    {fmtTime(sh.ends_at)}
                                                    {props.canManageAny &&
                                                    sh.staff
                                                        ? ` · ${sh.staff}`
                                                        : ''}
                                                </div>
                                            </div>
                                        </Link>
                                    ))}
                                {props.stats.timesheets_pending === 0 && (
                                    <div className="text-sm text-muted-foreground">
                                        No pending timesheets in this week.
                                    </div>
                                )}
                            </div>

                            <div>
                                <Link href="/operations/timesheets">
                                    <Button variant="outline" size="sm">
                                        Open Timesheets
                                    </Button>
                                </Link>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">
                                Incidents in this roster window
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-sm text-muted-foreground">
                                Quick jump into the shifts that have incidents
                                linked.
                            </div>
                            <div className="space-y-2">
                                {props.shifts
                                    .filter((s) => s.incidents_count > 0)
                                    .slice(0, 8)
                                    .map((sh) => (
                                        <Link
                                            key={sh.id}
                                            href={`/operations/shifts/${sh.id}`}
                                            className="block"
                                        >
                                            <div className="rounded-md border p-2 hover:bg-muted">
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="text-sm font-medium">
                                                        {sh.client}
                                                    </div>
                                                    <Badge variant="destructive">
                                                        {sh.incidents_count}{' '}
                                                        incident
                                                        {sh.incidents_count ===
                                                        1
                                                            ? ''
                                                            : 's'}
                                                    </Badge>
                                                </div>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {new Date(
                                                        sh.starts_at,
                                                    ).toLocaleDateString()}{' '}
                                                    · {fmtTime(sh.starts_at)}–
                                                    {fmtTime(sh.ends_at)}
                                                    {props.canManageAny &&
                                                    sh.staff
                                                        ? ` · ${sh.staff}`
                                                        : ''}
                                                </div>
                                            </div>
                                        </Link>
                                    ))}
                                {props.stats.incidents === 0 && (
                                    <div className="text-sm text-muted-foreground">
                                        No incidents linked to shifts in this
                                        week.
                                    </div>
                                )}
                            </div>
                            <div>
                                <Link href="/incidents">
                                    <Button variant="outline" size="sm">
                                        Open Incidents
                                    </Button>
                                </Link>
                            </div>
                        </CardContent>
                    </Card>
                </div>
                {/* Resolve overlap dialog */}
                <Dialog
                    open={!!resolveModal}
                    onOpenChange={(o) => !o && setResolveModal(null)}
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
                                            <div className="text-sm font-medium">
                                                A
                                            </div>
                                            {resolveState?.aLocked ? (
                                                <Badge variant="secondary">
                                                    Locked
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {new Date(
                                                resolveModal.a.starts_at,
                                            ).toLocaleDateString()}{' '}
                                            {fmtTime(resolveModal.a.starts_at)}–
                                            {fmtTime(resolveModal.a.ends_at)}
                                        </div>
                                        <div className="mt-1 text-xs">
                                            {resolveModal.a.client ??
                                                clientSingular}{' '}
                                            ·{' '}
                                            {resolveModal.a.staff ??
                                                'Unassigned'}{' '}
                                        </div>
                                        <div className="mt-2">
                                            <Link
                                                href={`/operations/shifts/${resolveModal.a.id}`}
                                            >
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    Open A
                                                </Button>
                                            </Link>
                                        </div>
                                    </div>

                                    <div className="rounded-md border p-3">
                                        <div className="flex items-center gap-2">
                                            <div className="text-sm font-medium">
                                                B
                                            </div>
                                            {resolveState?.bLocked ? (
                                                <Badge variant="secondary">
                                                    Locked
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {new Date(
                                                resolveModal.b.starts_at,
                                            ).toLocaleDateString()}{' '}
                                            {fmtTime(resolveModal.b.starts_at)}–
                                            {fmtTime(resolveModal.b.ends_at)}
                                        </div>
                                        <div className="mt-1 text-xs">
                                            {resolveModal.b.client ??
                                                clientSingular}{' '}
                                            ·{' '}
                                            {resolveModal.b.staff ??
                                                'Unassigned'}{' '}
                                        </div>
                                        <div className="mt-2">
                                            <Link
                                                href={`/operations/shifts/${resolveModal.b.id}`}
                                            >
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    Open B
                                                </Button>
                                            </Link>
                                        </div>
                                    </div>
                                </div>

                                {resolveModal.kind === 'staff' ? (
                                    <div className="space-y-3">
                                        <div className="text-sm text-muted-foreground">
                                            Choose the quickest safe fix.
                                            Suggestions consider time-off +
                                            existing roster conflicts + lowest
                                            weekly hours.
                                        </div>
                                        {resolveState?.bothLocked ? (
                                            <div className="text-sm text-muted-foreground">
                                                Both shifts are locked
                                                (completed). This overlap is
                                                historical and cannot be
                                                resolved from rostering.
                                            </div>
                                        ) : null}

                                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                            <div className="space-y-2">
                                                <div className="text-sm font-medium">
                                                    Keep A
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={
                                                            !!resolveState?.bLocked
                                                        }
                                                        onClick={() => {
                                                            if (
                                                                resolveState?.bLocked
                                                            )
                                                                return;
                                                            router.post(
                                                                `/operations/shifts/${resolveModal.b.id}/unassign`,
                                                                {
                                                                    return_to:
                                                                        '/operations/rostering',
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                    onSuccess:
                                                                        () =>
                                                                            setResolveModal(
                                                                                null,
                                                                            ),
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        Open B (unassign)
                                                    </Button>
                                                </div>

                                                <div className="rounded-md border p-2">
                                                    <div className="text-xs text-muted-foreground">
                                                        Reassign B to:
                                                    </div>
                                                    <div className="mt-2 flex items-center gap-2">
                                                        <Select
                                                            value={
                                                                resolveReassignSelection[
                                                                    resolveModal
                                                                        .b.id
                                                                ] ?? ''
                                                            }
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                setResolveReassignSelection(
                                                                    (prev) => ({
                                                                        ...prev,
                                                                        [resolveModal
                                                                            .b
                                                                            .id]:
                                                                            v,
                                                                    }),
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger className="w-[260px]">
                                                                <SelectValue placeholder="Suggested staff" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {availableStaffForShift(
                                                                    resolveModal.b,
                                                                )
                                                                    .filter(
                                                                        (u) =>
                                                                            u.id !==
                                                                            resolveModal.staffId,
                                                                    )
                                                                    .slice(
                                                                        0,
                                                                        12,
                                                                    )
                                                                    .map(
                                                                        (u) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    u.id
                                                                                }
                                                                                value={String(
                                                                                    u.id,
                                                                                )}
                                                                            >
                                                                                {
                                                                                    u.name
                                                                                }
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                            </SelectContent>
                                                        </Select>
                                                        <Button
                                                            size="sm"
                                                            disabled={
                                                                !!resolveState?.bLocked ||
                                                                !resolveReassignSelection[
                                                                    resolveModal
                                                                        .b.id
                                                                ]
                                                            }
                                                            onClick={() => {
                                                                if (
                                                                    resolveState?.bLocked
                                                                )
                                                                    return;
                                                                const uid =
                                                                    resolveReassignSelection[
                                                                        resolveModal
                                                                            .b
                                                                            .id
                                                                    ];
                                                                if (!uid)
                                                                    return;
                                                                router.post(
                                                                    `/operations/shifts/${resolveModal.b.id}/assign`,
                                                                    {
                                                                        user_id:
                                                                            uid,
                                                                        return_to:
                                                                            '/operations/rostering',
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                        onSuccess:
                                                                            () =>
                                                                                setResolveModal(
                                                                                    null,
                                                                                ),
                                                                    },
                                                                );
                                                            }}
                                                        >
                                                            Reassign B
                                                        </Button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="space-y-2">
                                                <div className="text-sm font-medium">
                                                    Keep B
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={
                                                            !!resolveState?.aLocked
                                                        }
                                                        onClick={() => {
                                                            if (
                                                                resolveState?.aLocked
                                                            )
                                                                return;
                                                            router.post(
                                                                `/operations/shifts/${resolveModal.a.id}/unassign`,
                                                                {
                                                                    return_to:
                                                                        '/operations/rostering',
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                    onSuccess:
                                                                        () =>
                                                                            setResolveModal(
                                                                                null,
                                                                            ),
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        Open A (unassign)
                                                    </Button>
                                                </div>

                                                <div className="rounded-md border p-2">
                                                    <div className="text-xs text-muted-foreground">
                                                        Reassign A to:
                                                    </div>
                                                    <div className="mt-2 flex items-center gap-2">
                                                        <Select
                                                            value={
                                                                resolveReassignSelection[
                                                                    resolveModal
                                                                        .a.id
                                                                ] ?? ''
                                                            }
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                setResolveReassignSelection(
                                                                    (prev) => ({
                                                                        ...prev,
                                                                        [resolveModal
                                                                            .a
                                                                            .id]:
                                                                            v,
                                                                    }),
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger className="w-[260px]">
                                                                <SelectValue placeholder="Suggested staff" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {availableStaffForShift(
                                                                    resolveModal.a,
                                                                )
                                                                    .filter(
                                                                        (u) =>
                                                                            u.id !==
                                                                            resolveModal.staffId,
                                                                    )
                                                                    .slice(
                                                                        0,
                                                                        12,
                                                                    )
                                                                    .map(
                                                                        (u) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    u.id
                                                                                }
                                                                                value={String(
                                                                                    u.id,
                                                                                )}
                                                                            >
                                                                                {
                                                                                    u.name
                                                                                }
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                            </SelectContent>
                                                        </Select>
                                                        <Button
                                                            size="sm"
                                                            disabled={
                                                                !!resolveState?.aLocked ||
                                                                !resolveReassignSelection[
                                                                    resolveModal
                                                                        .a.id
                                                                ]
                                                            }
                                                            onClick={() => {
                                                                if (
                                                                    resolveState?.aLocked
                                                                )
                                                                    return;
                                                                const uid =
                                                                    resolveReassignSelection[
                                                                        resolveModal
                                                                            .a
                                                                            .id
                                                                    ];
                                                                if (!uid)
                                                                    return;
                                                                router.post(
                                                                    `/operations/shifts/${resolveModal.a.id}/assign`,
                                                                    {
                                                                        user_id:
                                                                            uid,
                                                                        return_to:
                                                                            '/operations/rostering',
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                        onSuccess:
                                                                            () =>
                                                                                setResolveModal(
                                                                                    null,
                                                                                ),
                                                                    },
                                                                );
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
                                            This is a client double-booking.
                                            Resolve by opening one shift (so it
                                            becomes an open slot) and then
                                            adjust times/staffing.
                                        </div>
                                        {resolveState?.bothLocked ? (
                                            <div className="text-sm text-muted-foreground">
                                                Both shifts are locked
                                                (completed). This overlap is
                                                historical and cannot be
                                                resolved from rostering.
                                            </div>
                                        ) : null}
                                        {props.canManageAny ? (
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={
                                                        !!resolveState?.aLocked
                                                    }
                                                    onClick={() => {
                                                        if (
                                                            resolveState?.aLocked
                                                        )
                                                            return;
                                                        router.post(
                                                            `/operations/shifts/${resolveModal.a.id}/unassign`,
                                                            {
                                                                return_to:
                                                                    '/operations/rostering',
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                                onSuccess: () =>
                                                                    setResolveModal(
                                                                        null,
                                                                    ),
                                                            },
                                                        );
                                                    }}
                                                >
                                                    Open A (unassign)
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={
                                                        !!resolveState?.bLocked
                                                    }
                                                    onClick={() => {
                                                        if (
                                                            resolveState?.bLocked
                                                        )
                                                            return;
                                                        router.post(
                                                            `/operations/shifts/${resolveModal.b.id}/unassign`,
                                                            {
                                                                return_to:
                                                                    '/operations/rostering',
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                                onSuccess: () =>
                                                                    setResolveModal(
                                                                        null,
                                                                    ),
                                                            },
                                                        );
                                                    }}
                                                >
                                                    Open B (unassign)
                                                </Button>
                                            </div>
                                        ) : null}
                                    </div>
                                )}

                                <DialogFooter>
                                    <Button
                                        variant="outline"
                                        onClick={() => setResolveModal(null)}
                                    >
                                        Close
                                    </Button>
                                </DialogFooter>
                            </div>
                        ) : null}
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
