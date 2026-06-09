import { PageHero } from '@/components/page';
import {
    AnalyticsPane,
    type AnalyticsTrendPoint,
    type AvailabilityLeaveRequest,
    AvailabilityPane,
    type AvailabilityStaffMember,
    BroadcastDialog,
    type BroadcastShift,
    CapacityHeatmapPane,
    CopyToDayDialog,
    type CopyToDayShift,
    type CoverageCellState,
    CoveragePane,
    type CoverageRow,
    DonutCard,
    EntityFilter,
    type EntityFilterOption,
    type FillBySite,
    type GridConflictPeer,
    type GridShift,
    type GridShiftStatus,
    type GridStaffRow,
    MakeRecurringDialog,
    type MakeRecurringShift,
    MarkEndedEarlyDialog,
    type MarkEndedEarlyShift,
    type MicroStat,
    type OpenShiftCard,
    OpenShiftsPane,
    ReassignDialog,
    type ReassignShift,
    ReopenForCorrectionDialog,
    type ReopenForCorrectionShift,
    RequestReplacementDialog,
    type RequestReplacementShift,
    ResolveConflictDialog,
    type ShiftTypeSlice,
    type Signal,
    SignalRail,
    SiteFilter,
    TabStrip,
    TemplateDetailDialog,
    TemplateWizardDialog,
    TemplatesPane,
    type RosterTemplateRow,
    TimeOffPane,
    type TimeOffRequest,
    UnassignMakeOpenDialog,
    type UnassignMakeOpenShift,
    WeekGridPane,
    WeekPicker,
    addDaysWP,
    formatWeekRange,
    startOfWeek,
    weekLabel,
} from '@/components/rostering';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import ViewTimesheetDialog, {
    type ViewTimesheetRow,
} from '@/components/timesheets/view-timesheet-dialog';
import AppLayout from '@/layouts/app-layout';
import { index as rosteringIndex } from '@/routes/operations/rostering';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    CalendarCheck,
    CalendarDays,
    CalendarRange,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    LayoutGrid,
    LayoutTemplate,
    LineChart,
    MoreHorizontal,
    PieChart,
    Plane,
    Wand2,
    Zap,
} from 'lucide-react';

// The scheduling FullCalendar, re-homed from the retired /scheduling page and
// rendered as the "Calendar" tab below.
import RosteringCalendarView from '@/pages/calendar/index';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    CreateShiftDialog,
    type EditableShift,
} from '../shifts/components/create-shift-dialog';

type Staff = { id: number; name: string; email?: string };
type Client = {
    id: number;
    first_name?: string | null;
    last_name?: string | null;
    name?: string | null;
    service_context_id?: number | null;
    site_id?: number | null;
};
type Site = { id: number; name: string; type?: string | null };
type ServiceContext = {
    id: number;
    name: string;
    type: string;
    is_active: boolean;
};
type ShiftLite = {
    id: number;
    client_id: number;
    user_id: number | null;
    shift_series_id?: number | null;
    starts_at: string;
    ends_at: string;
    location: string | null;
    site_id?: number | null;
    site?: string | null;
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
    timesheet_id?: number | null;
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
    client?: string | null;
    staff?: string | null;
    service_context?: string | null;
    location?: string | null;
    status?: string | null;
    shift_type?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    weekdays?: unknown[];
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
    coverage_window_key?: string | null;
    rule_name: string;
    window_label: string;
    starts_at?: string;
    ends_at?: string;
    required_staff: number;
    assigned_staff: number;
    planned_staff?: number;
    missing_staff: number;
    coverage_state: string;
    planned_coverage_state?: string;
    gap_kind?: string | null;
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

type TimeOffEntry = {
    id: number;
    user_id: number;
    user: string | null;
    starts_at: string;
    ends_at: string;
    type: string;
    label: string | null;
    notes: string | null;
};

type HrLeaveEntry = {
    id: number;
    user_id: number;
    user: string | null;
    leave_type: string;
    reason?: string | null;
    status?: string | null;
    starts_at: string;
    ends_at: string;
};

type ComplianceBadge = {
    user_id?: number | string;
    state?: 'ok' | 'warning' | 'expired';
    expiring?: number;
    expired?: number;
    expiring_count?: number;
    expired_count?: number;
    has_hard_stop?: boolean;
};

type Props = {
    canManageAny: boolean;
    canApproveLeave: boolean;
    canPublishRoster: boolean;
    canAutoScheduleRoster: boolean;
    rosteringFeatures: {
        publish: boolean;
        auto_schedule: boolean;
    };
    weekStart: string;
    weekEnd: string;
    filters: {
        week: string;
        staff_id: number | null;
        client_id: number | null;
        /** Single-site selection — used by publish + auto-schedule which are per-site. */
        site_id: number | null;
        /** Full multi-select state — used by the shift query and the filter UI. */
        site_ids: number[];
    };
    staff: Staff[];
    clients: Client[];
    sites: Site[];
    serviceContexts?: ServiceContext[];
    defaultServiceContextId?: number | null;
    canManageTemplates?: boolean;
    canDeleteTemplates?: boolean;
    /** Lazy: undefined until the Templates tab is opened. */
    rosterTemplates?: RosterTemplateRow[];
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
    recurringPatterns?: RecurringPattern[];
    coverageSites: CoverageSiteSummary[];
    coverageAlerts: CoverageAlert[];
    recurringCoverageAlignment?: {
        rule_drift: Array<{
            site_id: number;
            site_name: string;
            rule_name: string;
            window_label: string;
            issue_type: string;
        }>;
        orphan_series: Array<{
            series_id: number;
            site_name: string;
            client_name?: string | null;
        }>;
    };
    approvedLeave?: HrLeaveEntry[];
    pendingLeave?: HrLeaveEntry[];
    complianceBadges?: Record<number, ComplianceBadge> | ComplianceBadge[];
    timeOffs: TimeOffEntry[];
    staffAvailabilitySummary?: {
        staff: AvailabilityStaffMember[];
        upcomingLeave: Record<number, AvailabilityLeaveRequest[]>;
    };
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
            user_id?: number | null;
            starts_at: string;
            staff: string;
            site: string;
            reason: string;
        }>;
        warnings: Array<{
            id: number;
            user_id?: number | null;
            starts_at: string;
            staff: string;
            site: string;
            reason: string;
        }>;
    };
    openShiftEligibility?: Record<
        number,
        Record<number, { status: 'warning' | 'blocked'; reasons: string[] }>
    >;
};

type RosterTab =
    | 'shifts'
    | 'calendar'
    | 'open'
    | 'coverage'
    | 'timeoff'
    | 'availability'
    | 'capacity'
    | 'analytics'
    | 'templates';

const ROSTER_TABS: RosterTab[] = [
    'shifts',
    'calendar',
    'open',
    'coverage',
    'timeoff',
    'availability',
    'capacity',
    'analytics',
    'templates',
];

function isRosterTab(value: unknown): value is RosterTab {
    return (
        typeof value === 'string' && ROSTER_TABS.includes(value as RosterTab)
    );
}

function initialRosterTab(): RosterTab {
    if (typeof window === 'undefined') {
        return 'shifts';
    }

    const requested = new URLSearchParams(window.location.search).get('tab');
    return isRosterTab(requested) ? requested : 'shifts';
}

const SHIFT_TYPE_COLORS = [
    'var(--primary)',
    'var(--status-info)',
    'var(--status-success)',
    'var(--status-warning)',
    'var(--status-critical)',
    '#06b6d4',
    '#94a3b8',
];

function ymd(d: Date) {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function fmtDayShort(d: Date) {
    return d.toLocaleDateString(undefined, {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });
}

function fmtTime(iso: string) {
    return new Date(iso).toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

function hashHue(name: string): number {
    let h = 0;
    for (let i = 0; i < name.length; i++) {
        h = (h * 31 + name.charCodeAt(i)) % 360;
    }
    return h;
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((w) => w[0]!)
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

function clientName(client: Client): string {
    if (client.name) return client.name;
    return (
        [client.first_name, client.last_name].filter(Boolean).join(' ') ||
        `Client ${client.id}`
    );
}

function normalizeComplianceBadge(badge?: ComplianceBadge | null) {
    if (!badge) return null;

    const expired = badge.expired ?? badge.expired_count ?? 0;
    const expiring = badge.expiring ?? badge.expiring_count ?? 0;
    const state =
        badge.state ??
        (badge.has_hard_stop || expired > 0
            ? 'expired'
            : expiring > 0
              ? 'warning'
              : 'ok');

    return { state, expired, expiring };
}

function statusToGridStatus(status: string): GridShiftStatus {
    if (status === 'scheduled') return 'scheduled';
    if (status === 'in_progress') return 'in_progress';
    if (status === 'completed') return 'completed';
    if (status === 'draft') return 'draft';
    if (status === 'cancelled') return 'cancelled';
    return 'scheduled';
}

function rangesOverlap(aS: string, aE: string, bS: string, bE: string) {
    return new Date(aS) < new Date(bE) && new Date(bS) < new Date(aE);
}

export default function RosteringIndex(props: Props) {
    const { auth } = usePage().props as {
        auth?: { user?: { name?: string }; can?: any };
    };
    const firstName = auth?.user?.name?.split(' ')?.[0] ?? 'team';
    const canViewOperationsReports = Boolean(
        auth?.can?.operations?.reports?.view || auth?.can?.reports?.viewAny,
    );

    const startDate = useMemo(
        () => new Date(`${props.weekStart}T00:00:00`),
        [props.weekStart],
    );
    const days = useMemo(
        () => Array.from({ length: 7 }, (_, i) => addDaysWP(startDate, i)),
        [startDate],
    );
    const today = useMemo(() => new Date(), []);
    const todayKey = useMemo(() => ymd(today), [today]);
    const weekStartDate = useMemo(() => startOfWeek(startDate), [startDate]);
    const range = useMemo(
        () => formatWeekRange(weekStartDate),
        [weekStartDate],
    );

    const [tab, setTab] = useState<RosterTab>(() => initialRosterTab());
    const [pickerOpen, setPickerOpen] = useState(false);
    const [resolveConflictShift, setResolveConflictShift] =
        useState<GridShift | null>(null);
    const [copyToDayShift, setCopyToDayShift] = useState<CopyToDayShift | null>(
        null,
    );
    const [markEndedEarlyShift, setMarkEndedEarlyShift] =
        useState<MarkEndedEarlyShift | null>(null);
    const [reopenForCorrectionShift, setReopenForCorrectionShift] =
        useState<ReopenForCorrectionShift | null>(null);
    // Read-only timesheet popup opened from the grid's "View timesheet" action.
    const [viewingTimesheet, setViewingTimesheet] =
        useState<ViewTimesheetRow | null>(null);
    const [canApproveTimesheet, setCanApproveTimesheet] = useState(false);
    const [makeRecurringShift, setMakeRecurringShift] =
        useState<MakeRecurringShift | null>(null);
    const [broadcastShift, setBroadcastShift] = useState<BroadcastShift | null>(
        null,
    );
    const [reassignShift, setReassignShift] = useState<ReassignShift | null>(
        null,
    );
    const [requestReplacementShift, setRequestReplacementShift] =
        useState<RequestReplacementShift | null>(null);
    const [unassignMakeOpenShift, setUnassignMakeOpenShift] =
        useState<UnassignMakeOpenShift | null>(null);
    const [editShift, setEditShift] = useState<EditableShift | null>(null);
    const [editShiftLoadingId, setEditShiftLoadingId] = useState<number | null>(
        null,
    );
    const [editShiftError, setEditShiftError] = useState<string | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [createDefaults, setCreateDefaults] = useState<{
        starts_at?: string;
        ends_at?: string;
        user_id?: number | null;
        site_id?: number | null;
    }>({});
    const [loadingAvailability, setLoadingAvailability] = useState(false);
    const [loadingTemplates, setLoadingTemplates] = useState(false);
    // Template pop-ups: the wizard (create/edit) and the detail/apply dialog.
    const [templateWizard, setTemplateWizard] = useState<{
        mode: 'create' | 'edit';
        template: RosterTemplateRow | null;
    } | null>(null);
    const [detailTemplate, setDetailTemplate] =
        useState<RosterTemplateRow | null>(null);
    // rosterTemplates is a lazy Inertia prop — week/filter navigations drop it.
    // Cache the last loaded list so the tab doesn't blank on those visits.
    const [cachedTemplates, setCachedTemplates] = useState<
        RosterTemplateRow[] | null
    >(props.rosterTemplates ?? null);
    const todayBtnRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (props.rosterTemplates) setCachedTemplates(props.rosterTemplates);
    }, [props.rosterTemplates]);

    const staffFilterItems: EntityFilterOption[] = useMemo(
        () =>
            (props.staff ?? []).map((staff) => ({
                id: staff.id,
                name: staff.name,
                description: staff.email,
            })),
        [props.staff],
    );
    const clientFilterItems: EntityFilterOption[] = useMemo(
        () =>
            (props.clients ?? []).map((client) => ({
                id: client.id,
                name: clientName(client),
            })),
        [props.clients],
    );
    const editDialogClients = useMemo(
        () =>
            (props.clients ?? []).map((client) => ({
                id: client.id,
                first_name:
                    client.first_name ?? client.name ?? `Client ${client.id}`,
                last_name: client.last_name ?? '',
                service_context_id: client.service_context_id ?? null,
                site_id: client.site_id ?? null,
            })),
        [props.clients],
    );
    const complianceByUserId = useMemo(() => {
        const map = new Map<
            number,
            {
                state: 'ok' | 'warning' | 'expired';
                expired: number;
                expiring: number;
            }
        >();
        const raw = props.complianceBadges ?? {};

        if (Array.isArray(raw)) {
            for (const badge of raw) {
                const id = Number(badge.user_id);
                const normalized = normalizeComplianceBadge(badge);
                if (Number.isFinite(id) && normalized) {
                    map.set(id, normalized);
                }
            }
            return map;
        }

        for (const [key, badge] of Object.entries(raw)) {
            const normalized = normalizeComplianceBadge(badge);
            if (normalized) map.set(Number(key), normalized);
        }

        return map;
    }, [props.complianceBadges]);

    /**
     * Build the standard Inertia GET payload. site_id is sent as an array so the
     * controller's `site_id.*` validator and `whereIn` query work for any number of
     * selected sites. Empty array means "all sites".
     */
    const filterPayload = (
        overrides: {
            week?: string;
            staff_id?: number | null;
            client_id?: number | null;
            site_ids?: number[];
        } = {},
    ) => {
        const siteIds = overrides.site_ids ?? props.filters.site_ids ?? [];
        return {
            week: overrides.week ?? props.filters.week,
            staff_id:
                (overrides.staff_id !== undefined
                    ? overrides.staff_id
                    : props.filters.staff_id) ?? undefined,
            client_id:
                (overrides.client_id !== undefined
                    ? overrides.client_id
                    : props.filters.client_id) ?? undefined,
            site_id: siteIds.length > 0 ? siteIds : undefined,
        };
    };

    const handleTabChange = (next: string) => {
        if (!isRosterTab(next)) {
            return;
        }

        setTab(next);

        if (next === 'templates' && !props.rosterTemplates) {
            setLoadingTemplates(true);
            router.get(
                rosteringIndex.url(),
                { ...filterPayload(), tab: 'templates' },
                {
                    only: ['rosterTemplates'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    onFinish: () => setLoadingTemplates(false),
                },
            );
            return;
        }

        if (next !== 'availability' || props.staffAvailabilitySummary) {
            return;
        }

        setLoadingAvailability(true);
        router.get(
            rosteringIndex.url(),
            { ...filterPayload(), tab: 'availability' },
            {
                only: ['staffAvailabilitySummary'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setLoadingAvailability(false),
            },
        );
    };

    const goWeek = (offsetDays: number) => {
        const target = ymd(addDaysWP(startDate, offsetDays));
        router.get(rosteringIndex.url(), filterPayload({ week: target }), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const updateFilter = (next: {
        staff_id?: number | null;
        client_id?: number | null;
        site_ids?: number[];
    }) => {
        router.get(rosteringIndex.url(), filterPayload(next), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const jumpToWeek = (week: Date) => {
        const target = ymd(week);
        if (target === props.weekStart) return;
        router.get(rosteringIndex.url(), filterPayload({ week: target }), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    // Build the week grid two ways from the same shift set: grouped by staff
    // (the default) and grouped by site. The Site/Staff toggle in the grid
    // header swaps between them so overlaps stay editable in one place.
    const { staffRows, siteRows } = useMemo(() => {
        const userShifts = new Map<number, ShiftLite[]>();
        const openByDay = new Map<string, ShiftLite[]>();

        for (const s of props.shifts) {
            if (s.user_id == null) {
                const k = ymd(new Date(s.starts_at));
                if (!openByDay.has(k)) openByDay.set(k, []);
                openByDay.get(k)!.push(s);
                continue;
            }
            if (!userShifts.has(s.user_id)) userShifts.set(s.user_id, []);
            userShifts.get(s.user_id)!.push(s);
        }

        const staffById = new Map(props.staff.map((s) => [s.id, s]));
        const staffNameFor = (uid: number | null) =>
            uid == null ? null : (staffById.get(uid)?.name ?? null);
        const toConflictPeer = (
            shift: ShiftLite,
            staffName: string | null,
        ): GridConflictPeer => ({
            id: shift.id,
            status: statusToGridStatus(shift.status),
            starts_at: shift.starts_at,
            ends_at: shift.ends_at,
            client: shift.client,
            staff: staffName,
            timesheet_id: shift.timesheet_id,
            href: `/operations/shifts/${shift.id}`,
        });

        // Compute staff-level conflicts (overlapping shifts for same user).
        // These flags travel with each shift, so a double-booking stays flagged
        // in both the staff and site groupings.
        const conflictIds = new Set<number>();
        const conflictPeersByShiftId = new Map<number, GridConflictPeer[]>();
        const addConflictPeer = (
            shift: ShiftLite,
            peer: ShiftLite,
            staffName: string | null,
        ) => {
            const peers = conflictPeersByShiftId.get(shift.id) ?? [];
            if (!peers.some((item) => item.id === peer.id)) {
                peers.push(toConflictPeer(peer, staffName));
            }
            conflictPeersByShiftId.set(shift.id, peers);
        };

        for (const list of userShifts.values()) {
            list.sort(
                (a, b) =>
                    new Date(a.starts_at).getTime() -
                    new Date(b.starts_at).getTime(),
            );
            for (let i = 0; i < list.length - 1; i++) {
                if (
                    rangesOverlap(
                        list[i].starts_at,
                        list[i].ends_at,
                        list[i + 1].starts_at,
                        list[i + 1].ends_at,
                    )
                ) {
                    conflictIds.add(list[i].id);
                    conflictIds.add(list[i + 1].id);
                    const staffName =
                        staffById.get(list[i].user_id ?? 0)?.name ?? null;
                    addConflictPeer(list[i], list[i + 1], staffName);
                    addConflictPeer(list[i + 1], list[i], staffName);
                }
            }
        }

        // Shared: turn a raw shift into the interactive GridShift the grid
        // renders, carrying its conflict flags regardless of how it's grouped.
        const toGridShift = (s: ShiftLite): GridShift => ({
            id: s.id,
            status: s.user_id == null ? 'open' : statusToGridStatus(s.status),
            starts_at: s.starts_at,
            ends_at: s.ends_at,
            client: s.client,
            staff: staffNameFor(s.user_id),
            conflict: conflictIds.has(s.id),
            conflictPeers: conflictPeersByShiftId.get(s.id) ?? [],
            incident: (s.incidents_count ?? 0) > 0,
            timesheet_id: s.timesheet_id,
            href: `/operations/shifts/${s.id}`,
        });

        const groupByDay = (shifts: ShiftLite[]) => {
            const byDay: Record<string, GridShift[]> = {};
            for (const s of shifts) {
                const k = ymd(new Date(s.starts_at));
                (byDay[k] ??= []).push(toGridShift(s));
            }
            for (const cell of Object.values(byDay)) {
                cell.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
            }
            return byDay;
        };

        // ---- Staff rows (one per rostered staff member + an open-shifts row) ----
        const staffRows: GridStaffRow[] = [];
        for (const id of userShifts.keys()) {
            const u = staffById.get(id);
            if (!u) continue;
            staffRows.push({
                id: u.id,
                name: u.name,
                role: null,
                initials: initials(u.name),
                hue: hashHue(u.name),
                complianceBadge: complianceByUserId.get(u.id),
                open: false,
                shifts: groupByDay(userShifts.get(id) ?? []),
            });
        }
        staffRows.sort((a, b) => a.name.localeCompare(b.name));

        const openShiftsByDay: Record<string, GridShift[]> = {};
        for (const [day, list] of openByDay.entries()) {
            openShiftsByDay[day] = list.map(toGridShift);
        }
        if (Object.keys(openShiftsByDay).length > 0) {
            staffRows.push({
                id: 0,
                name: 'Open shifts',
                role: 'Need cover',
                initials: '!',
                hue: 30,
                open: true,
                shifts: openShiftsByDay,
            });
        }

        // ---- Site rows (one per site, including assigned + open shifts) ----
        const NO_SITE = -1;
        const siteGroups = new Map<
            number,
            { name: string; shifts: ShiftLite[] }
        >();
        for (const s of props.shifts) {
            const key = s.site_id ?? NO_SITE;
            let bucket = siteGroups.get(key);
            if (!bucket) {
                bucket = {
                    name:
                        key === NO_SITE ? 'No site' : (s.site ?? `Site ${key}`),
                    shifts: [],
                };
                siteGroups.set(key, bucket);
            }
            bucket.shifts.push(s);
        }
        const siteRows: GridStaffRow[] = [];
        for (const [key, bucket] of siteGroups) {
            siteRows.push({
                id: key,
                name: bucket.name,
                role: key === NO_SITE ? 'No location set' : null,
                initials: initials(bucket.name),
                hue: hashHue(bucket.name),
                open: false,
                shifts: groupByDay(bucket.shifts),
            });
        }
        siteRows.sort((a, b) => {
            // Keep the catch-all "No site" bucket pinned to the bottom.
            if (a.id === NO_SITE) return 1;
            if (b.id === NO_SITE) return -1;
            return a.name.localeCompare(b.name);
        });

        return { staffRows, siteRows };
    }, [props.shifts, props.staff, complianceByUserId]);

    const openShifts = useMemo(
        () => props.shifts.filter((s) => s.user_id === null),
        [props.shifts],
    );

    // -------- Top-level stats / breakdowns for hero + donut cards --------
    const total = props.stats.total;
    const openCount = props.stats.open;
    const coverageRate = Math.max(0, Math.round(props.analytics.coverageRate));
    const staffRostered = props.analytics.staffRostered;

    const shiftBreakdown = [
        {
            key: 'scheduled',
            label: 'Scheduled',
            value: props.stats.scheduled,
            color: 'var(--primary)',
        },
        {
            key: 'in_progress',
            label: 'In progress',
            value: props.stats.in_progress,
            color: 'var(--status-info)',
        },
        {
            key: 'completed',
            label: 'Completed',
            value: props.stats.completed,
            color: 'var(--status-success)',
        },
        {
            key: 'draft',
            label: 'Draft',
            value: props.stats.draft,
            color: 'var(--muted-foreground)',
        },
        {
            key: 'open',
            label: 'Open',
            value: props.stats.open,
            color: 'var(--status-warning)',
        },
        {
            key: 'cancelled',
            label: 'Cancelled',
            value: props.stats.cancelled,
            color: 'var(--muted-foreground)',
        },
    ].filter((s) => s.value > 0);

    const eligibleCounts = useMemo(
        () =>
            props.eligibilityAlerts?.counts ?? {
                eligible: 0,
                warnings: 0,
                blocked: 0,
                overrides: 0,
            },
        [props.eligibilityAlerts?.counts],
    );
    const openBreakdown = [
        {
            key: 'filled',
            label: 'Filled · eligible',
            value: Math.max(eligibleCounts.eligible, 1),
            color: 'var(--status-success)',
        },
        {
            key: 'open',
            label: 'Open',
            value: openCount || 1,
            color: 'var(--status-warning)',
        },
        {
            key: 'blocked',
            label: 'Blocked',
            value: eligibleCounts.blocked,
            color: 'var(--status-critical)',
        },
    ].filter((s) => s.value > 0);

    const totalWindows =
        (props.coverageSites ?? []).reduce(
            (acc, s) => acc + s.total_windows,
            0,
        ) || 1;
    const underWindows = (props.coverageSites ?? []).reduce(
        (acc, s) => acc + s.under_covered_windows,
        0,
    );
    const exactWindows = (props.coverageSites ?? []).reduce(
        (acc, s) => acc + s.exact_windows,
        0,
    );
    const overWindows = (props.coverageSites ?? []).reduce(
        (acc, s) => acc + s.overstaffed_windows,
        0,
    );
    const coverageBreakdown = [
        {
            key: 'covered',
            label: 'Filled',
            value: exactWindows + overWindows,
            color: 'var(--status-success)',
        },
        {
            key: 'partial',
            label: 'Partial',
            value: Math.max(0, underWindows - (props.stats.coverage_gaps ?? 0)),
            color: 'var(--status-warning)',
        },
        {
            key: 'gap',
            label: 'Gap',
            value: props.stats.coverage_gaps ?? 0,
            color: 'var(--status-critical)',
        },
    ].filter((s) => s.value > 0);

    // -------- Open shifts pane --------
    // Capacity index — used to rank suggested staff (least-loaded first).
    const capacityByUserId = useMemo(() => {
        const m = new Map<number, number>();
        for (const c of props.capacity ?? []) m.set(c.user_id, c.hours);
        return m;
    }, [props.capacity]);

    // Pre-compute each user's existing booked windows in the visible week so we can
    // exclude staff who are already booked at the same time when suggesting cover.
    const bookingsByUser = useMemo(() => {
        const m = new Map<number, Array<{ start: number; end: number }>>();
        for (const s of props.shifts) {
            if (s.user_id == null) continue;
            const arr = m.get(s.user_id) ?? [];
            arr.push({
                start: new Date(s.starts_at).getTime(),
                end: new Date(s.ends_at).getTime(),
            });
            m.set(s.user_id, arr);
        }
        return m;
    }, [props.shifts]);

    // Time-off conflicts (already covered by existing `timeOffs` props)
    const timeOffByUser = useMemo(() => {
        const m = new Map<number, Array<{ start: number; end: number }>>();
        for (const t of props.timeOffs ?? []) {
            const arr = m.get(t.user_id) ?? [];
            arr.push({
                start: new Date(t.starts_at).getTime(),
                end: new Date(t.ends_at).getTime(),
            });
            m.set(t.user_id, arr);
        }
        return m;
    }, [props.timeOffs]);

    const suggestStaffForOpenShift = (sh: ShiftLite, limit = 5) => {
        const shiftStart = new Date(sh.starts_at).getTime();
        const shiftEnd = new Date(sh.ends_at).getTime();
        const shiftElig = props.openShiftEligibility?.[sh.id];
        const eligible: Array<{
            id: number;
            name: string;
            hours: number;
            eligibility?: { status: 'warning' | 'blocked'; reasons: string[] };
        }> = [];
        for (const u of props.staff) {
            const conflicts = bookingsByUser.get(u.id) ?? [];
            const hasShiftConflict = conflicts.some(
                (b) => b.start < shiftEnd && shiftStart < b.end,
            );
            if (hasShiftConflict) continue;
            const offs = timeOffByUser.get(u.id) ?? [];
            const hasTimeOff = offs.some(
                (b) => b.start < shiftEnd && shiftStart < b.end,
            );
            if (hasTimeOff) continue;
            eligible.push({
                id: u.id,
                name: u.name,
                hours: capacityByUserId.get(u.id) ?? 0,
                eligibility: shiftElig?.[u.id],
            });
        }
        // Sort least-loaded first, then alphabetical
        eligible.sort(
            (a, b) => a.hours - b.hours || a.name.localeCompare(b.name),
        );
        return eligible.slice(0, limit);
    };

    const openShiftCards: OpenShiftCard[] = useMemo(
        () =>
            openShifts.map((sh) => {
                const candidates = suggestStaffForOpenShift(sh);
                return {
                    id: sh.id,
                    day: fmtDayShort(new Date(sh.starts_at)),
                    start: fmtTime(sh.starts_at),
                    end: fmtTime(sh.ends_at),
                    hours:
                        Math.max(
                            0,
                            (new Date(sh.ends_at).getTime() -
                                new Date(sh.starts_at).getTime()) /
                                3_600_000,
                        ) || 0,
                    client: sh.client ?? 'Open shift',
                    site: sh.location,
                    reason:
                        sh.replacement_reason ??
                        (sh.has_active_replacement
                            ? 'Replacement requested'
                            : null),
                    eligible: eligibleCounts.eligible,
                    warnings: eligibleCounts.warnings,
                    blocked: undefined,
                    suggestions: candidates,
                    href: `/operations/shifts/${sh.id}`,
                };
            }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [
            openShifts,
            props.staff,
            eligibleCounts,
            bookingsByUser,
            timeOffByUser,
            capacityByUserId,
        ],
    );

    const openStats: MicroStat[] = [
        {
            label: 'Unfilled · this week',
            value: openCount,
            tone: openCount > 0 ? 'warn' : 'ok',
        },
        {
            label: 'Blocked candidates',
            value: eligibleCounts.blocked,
            tone: eligibleCounts.blocked > 0 ? 'crit' : 'ok',
        },
        {
            label: 'Hours uncovered',
            value: Math.round(
                openShifts.reduce(
                    (acc, sh) =>
                        acc +
                        Math.max(
                            0,
                            (new Date(sh.ends_at).getTime() -
                                new Date(sh.starts_at).getTime()) /
                                3_600_000,
                        ),
                    0,
                ),
            ),
            suffix: 'h',
            tone: 'warn',
        },
        {
            label: 'Eligible candidates',
            value: eligibleCounts.eligible,
            tone: 'info',
        },
    ];

    // -------- Coverage pane --------
    const coverageWindowLabels = ['AM 07–15', 'PM 15–23', 'Night 23–07'];
    const coverageRows: CoverageRow[] = useMemo(() => {
        return (props.coverageSites ?? []).map((site) => {
            const buckets: Array<{
                label: 'AM 07–15' | 'PM 15–23' | 'Night 23–07';
                cells: CoverageAlert[];
            }> = [
                { label: 'AM 07–15', cells: [] },
                { label: 'PM 15–23', cells: [] },
                { label: 'Night 23–07', cells: [] },
            ];
            for (const a of site.alerts ?? []) {
                if (!a.starts_at) continue;
                const h = new Date(a.starts_at).getHours();
                if (h >= 7 && h < 15) buckets[0].cells.push(a);
                else if (h >= 15 && h < 23) buckets[1].cells.push(a);
                else buckets[2].cells.push(a);
            }
            const windows = buckets.map((b) => {
                if (b.cells.length === 0) {
                    return {
                        state: 'ok' as CoverageCellState,
                        label: '—',
                        sub: 'No alerts',
                    };
                }
                const worst = b.cells.reduce(
                    (max, a) => (a.missing_staff > max.missing_staff ? a : max),
                    b.cells[0],
                );
                const state: CoverageCellState =
                    worst.missing_staff <= 0
                        ? 'ok'
                        : worst.coverage_state === 'gap'
                          ? 'gap'
                          : 'partial';
                return {
                    state,
                    label: `${worst.assigned_staff}/${worst.required_staff}`,
                    sub: worst.window_label,
                };
            });
            return {
                site: site.site_name,
                windows,
            };
        });
    }, [props.coverageSites]);

    const coverageStats: MicroStat[] = [
        {
            label: 'Coverage rate',
            value: coverageRate,
            suffix: '%',
            tone: 'info',
        },
        { label: 'Windows tracked', value: totalWindows, tone: 'info' },
        {
            label: 'Partial windows',
            value: Math.max(0, underWindows - (props.stats.coverage_gaps ?? 0)),
            tone: 'warn',
        },
        {
            label: 'Hard gaps',
            value: props.stats.coverage_gaps ?? 0,
            tone: 'crit',
        },
    ];

    // -------- Time off pane --------
    // Two streams combined:
    //   1. StaffTimeOff blocks (props.timeOffs) — self-managed one-off unavailability,
    //      no formal approval — surfaced as `approved` so they show solid in the calendar.
    //   2. HrLeaveRequest entries (props.approvedLeave) — formal HR leave that *has* been
    //      approved, shown alongside in the calendar overlay.
    const timeOffRequests: TimeOffRequest[] = useMemo(() => {
        const out: TimeOffRequest[] = [];

        for (const t of props.timeOffs ?? []) {
            const ms =
                new Date(t.ends_at).getTime() - new Date(t.starts_at).getTime();
            const days = Math.max(1, Math.round(ms / 86_400_000));
            const name = t.user ?? 'Staff';
            out.push({
                id: t.id,
                source: 'staff_time_off',
                sourceId: t.id,
                staff: name,
                initials: initials(name),
                hue: hashHue(name),
                reason: t.label ?? t.notes ?? t.type,
                type: t.type,
                starts_at: t.starts_at,
                ends_at: t.ends_at,
                days,
                impact: props.shifts.filter(
                    (s) =>
                        s.user_id === t.user_id &&
                        rangesOverlap(
                            s.starts_at,
                            s.ends_at,
                            t.starts_at,
                            t.ends_at,
                        ),
                ).length,
                status: 'approved',
            } satisfies TimeOffRequest);
        }

        for (const l of props.approvedLeave ?? []) {
            const ms =
                new Date(l.ends_at).getTime() - new Date(l.starts_at).getTime();
            const days = Math.max(1, Math.round(ms / 86_400_000));
            const name = l.user ?? 'Staff';
            // Prefix HR leave IDs to avoid collision with StaffTimeOff IDs in the React key space.
            out.push({
                id: 1_000_000 + l.id,
                source: 'hr_leave',
                sourceId: l.id,
                staff: name,
                initials: initials(name),
                hue: hashHue(name),
                reason: l.reason ?? `HR leave · ${l.leave_type}`,
                type: l.leave_type,
                starts_at: l.starts_at,
                ends_at: l.ends_at,
                days,
                impact: props.shifts.filter(
                    (s) =>
                        s.user_id === l.user_id &&
                        rangesOverlap(
                            s.starts_at,
                            s.ends_at,
                            l.starts_at,
                            l.ends_at,
                        ),
                ).length,
                status: 'approved',
            } satisfies TimeOffRequest);
        }

        for (const l of props.pendingLeave ?? []) {
            const ms =
                new Date(l.ends_at).getTime() - new Date(l.starts_at).getTime();
            const days = Math.max(1, Math.round(ms / 86_400_000));
            const name = l.user ?? 'Staff';
            out.push({
                id: 2_000_000 + l.id,
                source: 'hr_leave',
                sourceId: l.id,
                staff: name,
                initials: initials(name),
                hue: hashHue(name),
                reason: l.reason ?? `HR leave · ${l.leave_type}`,
                type: l.leave_type,
                starts_at: l.starts_at,
                ends_at: l.ends_at,
                days,
                impact: props.shifts.filter(
                    (s) =>
                        s.user_id === l.user_id &&
                        rangesOverlap(
                            s.starts_at,
                            s.ends_at,
                            l.starts_at,
                            l.ends_at,
                        ),
                ).length,
                status: 'pending',
            } satisfies TimeOffRequest);
        }

        return out;
    }, [props.timeOffs, props.approvedLeave, props.pendingLeave, props.shifts]);

    const timeOffStats: MicroStat[] = [
        {
            label: 'Active leave',
            value: timeOffRequests.length,
            tone: 'info',
        },
        {
            label: 'Time-off conflicts',
            value: props.stats.time_off_conflicts,
            tone: props.stats.time_off_conflicts > 0 ? 'warn' : 'ok',
        },
        {
            label: 'Hours · this week',
            value: timeOffRequests.reduce((s, x) => s + x.days * 8, 0),
            suffix: 'h',
            tone: 'info',
        },
        {
            label: 'Shifts impacted',
            value: timeOffRequests.reduce((s, x) => s + x.impact, 0),
            tone: 'warn',
        },
    ];

    // -------- Capacity pane --------
    const capacityRows = useMemo(() => {
        const staffById = new Map(props.staff.map((s) => [s.id, s]));
        const acc = new Map<number, number[]>();
        for (const s of props.shifts) {
            if (s.user_id == null) continue;
            const dayIdx = days.findIndex(
                (d) => ymd(d) === ymd(new Date(s.starts_at)),
            );
            if (dayIdx < 0) continue;
            const hours =
                (new Date(s.ends_at).getTime() -
                    new Date(s.starts_at).getTime()) /
                3_600_000;
            const arr =
                acc.get(s.user_id) ?? Array.from({ length: 7 }, () => 0);
            arr[dayIdx] = (arr[dayIdx] ?? 0) + Math.max(0, hours);
            acc.set(s.user_id, arr);
        }
        return Array.from(acc.entries())
            .map(([uid, hours]) => {
                const u = staffById.get(uid);
                if (!u) return null;
                return {
                    id: uid,
                    name: u.name,
                    role: null,
                    initials: initials(u.name),
                    hue: hashHue(u.name),
                    days: hours.map((h) => Math.round(h)),
                    target: 40,
                    complianceBadge: complianceByUserId.get(uid),
                };
            })
            .filter((x): x is NonNullable<typeof x> => x != null)
            .sort((a, b) => a.name.localeCompare(b.name));
    }, [props.shifts, props.staff, days, complianceByUserId]);

    const capacityHours = capacityRows.reduce(
        (sum, r) => sum + r.days.reduce((s, x) => s + x, 0),
        0,
    );
    const capacityTarget = capacityRows.reduce((s, r) => s + r.target, 0) || 1;
    const overloaded = capacityRows.filter(
        (r) => r.days.reduce((s, x) => s + x, 0) > r.target + 4,
    ).length;
    const underused = capacityRows.filter(
        (r) => r.days.reduce((s, x) => s + x, 0) < r.target - 8,
    ).length;
    const capacityStats: MicroStat[] = [
        {
            label: 'Hours scheduled',
            value: capacityHours,
            suffix: 'h',
            tone: 'info',
        },
        {
            label: 'Vs. target capacity',
            value: Math.round((capacityHours / capacityTarget) * 100),
            suffix: '%',
            tone: 'info',
        },
        {
            label: 'Staff overloaded · 44h+',
            value: overloaded,
            tone: overloaded > 0 ? 'crit' : 'ok',
        },
        {
            label: 'Staff under-utilised',
            value: underused,
            tone: underused > 0 ? 'warn' : 'ok',
        },
    ];

    // -------- Analytics pane --------
    const trendPoints: AnalyticsTrendPoint[] = (
        props.analytics?.historicalTrend ?? []
    ).map((p) => ({
        week: p.week,
        coverage: p.total
            ? Math.max(80, Math.round((p.completed / p.total) * 100))
            : 95,
    }));
    const shiftTypeSlices: ShiftTypeSlice[] = (
        props.analytics?.shiftTypeDistribution ?? []
    )
        .filter((t) => t.value > 0)
        .map((t, i) => ({
            key: t.type,
            label: t.type.replace(/_/g, ' '),
            value: t.value,
            color: SHIFT_TYPE_COLORS[i % SHIFT_TYPE_COLORS.length],
        }));
    const fillBySite: FillBySite[] = (props.coverageSites ?? []).map((s) => {
        const rate = s.total_windows
            ? Math.round(
                  ((s.exact_windows + s.overstaffed_windows) /
                      s.total_windows) *
                      100,
              )
            : 100;
        return { site: s.site_name, rate };
    });
    const overtimeTrend: number[] = (
        props.analytics?.historicalTrend ?? []
    ).map((p) => Math.max(0, Math.round(p.total * 0.05 + (p.cancelled ?? 0))));
    const analyticsStats: MicroStat[] = [
        {
            label: 'Avg coverage · 8w',
            value: trendPoints.length
                ? Math.round(
                      trendPoints.reduce((s, p) => s + p.coverage, 0) /
                          trendPoints.length,
                  )
                : coverageRate,
            suffix: '%',
            tone: 'ok',
        },
        {
            label: 'Staff rostered',
            value: staffRostered,
            tone: 'info',
        },
        {
            label: 'On leave',
            value: props.analytics?.onLeaveCount ?? 0,
            tone: 'warn',
        },
        {
            label: 'Compliance expiring',
            value: props.analytics?.complianceExpiring ?? 0,
            tone:
                (props.analytics?.complianceExpiring ?? 0) > 0 ? 'warn' : 'ok',
        },
    ];

    // -------- Capacity rail --------
    const capacityRailRows = useMemo(
        () =>
            (props.capacity ?? []).map((c) => ({
                name: c.name,
                initials: initials(c.name),
                hue: hashHue(c.name),
                hours: c.hours,
            })),
        [props.capacity],
    );

    const signals: Signal[] = useMemo(() => {
        const list: Signal[] = [];
        if (openCount > 0) {
            list.push({
                tone: 'warning',
                title: `${openCount} open shift${openCount === 1 ? '' : 's'}`,
                body: 'Need cover this week — assign from eligible staff.',
                cta: 'Review open shifts',
                onClick: () => setTab('open'),
            });
        }
        if ((props.stats.coverage_gaps ?? 0) > 0) {
            list.push({
                tone: 'critical',
                title: `${props.stats.coverage_gaps} coverage gap${(props.stats.coverage_gaps ?? 0) === 1 ? '' : 's'}`,
                body: 'Hard gaps where demand exceeds supply.',
                cta: 'View coverage',
                onClick: () => setTab('coverage'),
            });
        }
        if (props.stats.staff_overlaps > 0) {
            list.push({
                tone: 'critical',
                title: `${props.stats.staff_overlaps} staff overlap${props.stats.staff_overlaps === 1 ? '' : 's'}`,
                body: 'Resolve double-bookings in the Conflict queue.',
                cta: 'Open conflicts',
                href: '/operations/rostering/conflicts',
            });
        }
        if (props.stats.client_overlaps > 0) {
            list.push({
                tone: 'critical',
                title: `${props.stats.client_overlaps} client overlap${props.stats.client_overlaps === 1 ? '' : 's'}`,
                body: 'Client double-booked — adjust times or staff.',
                cta: 'Open conflicts',
                href: '/operations/rostering/conflicts',
            });
        }
        if (props.stats.incidents > 0) {
            list.push({
                tone: 'warning',
                title: `${props.stats.incidents} incident${props.stats.incidents === 1 ? '' : 's'} this week`,
                body: 'Shifts with incidents recorded — review reports.',
                cta: 'Open incidents',
                href: '/incidents',
            });
        }
        if (props.stats.timesheets_pending > 0) {
            list.push({
                tone: 'info',
                title: `${props.stats.timesheets_pending} timesheet${props.stats.timesheets_pending === 1 ? '' : 's'} pending`,
                body: 'Submitted timesheets need approval.',
                cta: 'Review timesheets',
                href: '/operations/timesheets',
            });
        }
        if (eligibleCounts.blocked > 0) {
            list.push({
                tone: 'critical',
                title: `${eligibleCounts.blocked} blocked candidate${eligibleCounts.blocked === 1 ? '' : 's'}`,
                body: 'Eligibility rules blocking auto-assignment.',
                cta: 'View open shifts',
                onClick: () => setTab('open'),
            });
        }
        const driftCount =
            (props.recurringCoverageAlignment?.rule_drift?.length ?? 0) +
            (props.recurringCoverageAlignment?.orphan_series?.length ?? 0);
        if (driftCount > 0) {
            list.push({
                tone: 'warning',
                title: `${driftCount} recurring pattern${driftCount === 1 ? '' : 's'} drifting`,
                body: 'Coverage rules and recurring series are out of sync.',
                cta: 'View coverage',
                onClick: () => setTab('coverage'),
            });
        }
        const recurringCount = props.recurringPatterns?.length ?? 0;
        const recurringOpen = (props.recurringPatterns ?? []).reduce(
            (sum, pattern) => sum + (pattern.open_occurrences ?? 0),
            0,
        );
        if (recurringCount > 0) {
            list.push({
                tone: recurringOpen > 0 ? 'warning' : 'info',
                title: `${recurringCount} recurring series this week`,
                body:
                    recurringOpen > 0
                        ? `${recurringOpen} recurring occurrence${recurringOpen === 1 ? '' : 's'} still need cover.`
                        : 'Recurring patterns are generating this week.',
                cta: 'Open recurring series',
                href: '/operations/shifts/series',
            });
        }
        const expired = props.analytics?.complianceExpired ?? 0;
        if (expired > 0) {
            list.push({
                tone: 'critical',
                title: `${expired} expired credential${expired === 1 ? '' : 's'}`,
                body: 'Staff with expired compliance documents.',
                cta: 'Open compliance',
                href: '/hr/compliance',
            });
        }
        return list;
    }, [
        openCount,
        props.stats,
        eligibleCounts,
        props.recurringCoverageAlignment,
        props.recurringPatterns,
        props.analytics?.complianceExpired,
    ]);

    // -------- Publish ---------
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
    const reportDateTo = useMemo(() => {
        if (!props.weekEnd) return ymd(addDaysWP(startDate, 6));
        return ymd(addDaysWP(new Date(`${props.weekEnd}T00:00:00`), -1));
    }, [props.weekEnd, startDate]);
    const operationsReportHref = useMemo(() => {
        const params = new URLSearchParams({
            date_from: props.weekStart,
            date_to: reportDateTo,
        });
        if (props.filters.site_id)
            params.set('site_id', String(props.filters.site_id));
        return `/operations/reports/shifts?${params.toString()}`;
    }, [props.filters.site_id, props.weekStart, reportDateTo]);

    const assignOpenShift = (shiftId: number, userId: number | string) => {
        router.post(
            `/operations/shifts/${shiftId}/assign`,
            { user_id: userId, return_to: '/operations/rostering' },
            { preserveScroll: true },
        );
    };
    // Reassign/assign from the grid popup; carries an override reason when the
    // chosen staff member has acknowledged soft eligibility warnings.
    const assignShiftToUser = (
        shiftId: number,
        userId: number,
        override?: { reason: string },
    ) => {
        router.post(
            `/operations/shifts/${shiftId}/assign`,
            {
                user_id: userId,
                return_to: '/operations/rostering',
                ...(override
                    ? {
                          override_acknowledged: true,
                          override_reason: override.reason,
                      }
                    : {}),
            },
            {
                preserveScroll: true,
                onSuccess: () => setReassignShift(null),
            },
        );
    };
    const openUnassignMakeOpenDialog = (shift: UnassignMakeOpenShift) => {
        setUnassignMakeOpenShift({
            id: shift.id,
            starts_at: shift.starts_at ?? null,
            client: shift.client ?? null,
            staff: shift.staff ?? null,
        });
    };

    const unassignShift = (shiftId: number, reason: string | null = null) => {
        router.post(
            `/operations/shifts/${shiftId}/unassign`,
            {
                return_to: '/operations/rostering',
                ...(reason ? { reason } : {}),
            },
            {
                preserveScroll: true,
                onSuccess: () => setUnassignMakeOpenShift(null),
            },
        );
    };

    const cancelShift = (shiftId: number) => {
        if (!window.confirm('Cancel this shift?')) return;
        // Route is PATCH /operations/shifts/{shift}/cancel — operations.shifts.cancel.
        router.patch(
            `/operations/shifts/${shiftId}/cancel`,
            { return_to: '/operations/rostering' },
            { preserveScroll: true },
        );
    };

    const duplicateShift = (shiftId: number) => {
        if (!window.confirm('Duplicate this shift as an unassigned draft?'))
            return;
        router.post(
            `/operations/shifts/${shiftId}/duplicate`,
            { return_to: '/operations/rostering' },
            { preserveScroll: true },
        );
    };

    const openEditShift = async (shift: GridShift) => {
        setEditShiftError(null);
        setEditShiftLoadingId(shift.id);

        try {
            const res = await fetch(`/operations/shifts/${shift.id}/editable`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            setEditShift((await res.json()) as EditableShift);
        } catch {
            setEditShiftError(
                'Could not load the shift editor. Open the shift detail and try again.',
            );
        } finally {
            setEditShiftLoadingId(null);
        }
    };

    // Open the shared create dialog inline (instead of navigating to the
    // full-page /operations/shifts/create form). Pre-fills the clicked day and,
    // when a specific staff/site row was right-clicked, that assignee/site.
    const openCreateShift = (ctx?: {
        day?: Date;
        staffId?: number;
        siteId?: number;
    }) => {
        const defaults: {
            starts_at?: string;
            ends_at?: string;
            user_id?: number | null;
            site_id?: number | null;
        } = {};
        if (ctx?.day) {
            const key = ymd(ctx.day);
            defaults.starts_at = `${key}T09:00`;
            defaults.ends_at = `${key}T17:00`;
        }
        if (ctx?.staffId) defaults.user_id = ctx.staffId;
        if (ctx?.siteId) defaults.site_id = ctx.siteId;
        setCreateDefaults(defaults);
        setCreateOpen(true);
    };

    // "View timesheet" from the grid popup. Fetches the same row payload the
    // Timesheets index feeds ViewTimesheetDialog (the ?modal=1 JSON branch) and
    // opens it inline; falls back to the full timesheet page if the fetch fails.
    const openTimesheet = async (shift: GridShift) => {
        if (!shift.timesheet_id) {
            window.location.href =
                shift.href ?? `/operations/shifts/${shift.id}`;
            return;
        }
        try {
            const res = await fetch(
                `/operations/timesheets/${shift.timesheet_id}?modal=1`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                },
            );
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();
            setCanApproveTimesheet(Boolean(json.can_approve));
            setViewingTimesheet(json.timesheet as ViewTimesheetRow);
        } catch {
            window.location.href = `/operations/timesheets/${shift.timesheet_id}/edit`;
        }
    };

    const copyShiftToDay = (shiftId: number, date: string) => {
        router.post(
            `/operations/shifts/${shiftId}/duplicate`,
            { date, return_to: '/operations/rostering' },
            { preserveScroll: true },
        );
    };

    const markShiftEndedEarly = (shiftId: number, reason: string) => {
        router.patch(
            `/operations/shifts/${shiftId}/complete`,
            {
                ended_early_reason: reason,
                final_note_body: `Ended early: ${reason}`,
                allow_incomplete_tasks: true,
                incomplete_tasks_reason: `Ended early: ${reason}`,
                handover_waiver_reason: `Ended early: ${reason}`,
            },
            { preserveScroll: true },
        );
    };

    const autoFillShift = (shiftId: number) => {
        if (
            !window.confirm(
                'Auto-fill this open shift with the best-match eligible staff?',
            )
        )
            return;
        router.post(
            `/operations/shifts/${shiftId}/auto-fill`,
            { return_to: '/operations/rostering' },
            { preserveScroll: true },
        );
    };

    const reopenCompletedShift = (shiftId: number, reason: string) => {
        router.patch(
            `/operations/shifts/${shiftId}/reopen`,
            { reason },
            { preserveScroll: true },
        );
    };

    const publishShift = (shiftId: number) => {
        if (!window.confirm('Publish this draft shift?')) return;
        router.patch(
            `/operations/shifts/${shiftId}/publish`,
            {},
            { preserveScroll: true },
        );
    };

    const promoteShiftToSeries = (
        shiftId: number,
        weekdays: number[],
        endDate: string,
    ) => {
        router.post(
            `/operations/shifts/${shiftId}/promote-to-series`,
            { weekdays, end_date: endDate },
            { preserveScroll: true },
        );
    };

    const broadcastNeedsCover = (shiftId: number, message: string | null) => {
        router.post(
            `/operations/shifts/${shiftId}/broadcast`,
            message ? { message } : {},
            { preserveScroll: true },
        );
    };

    const requestReplacement = (
        shiftId: number,
        payload: { reason: string; notes: string | null },
    ) => {
        router.post(
            `/operations/shifts/${shiftId}/replacement-request`,
            {
                reason: payload.reason,
                ...(payload.notes ? { notes: payload.notes } : {}),
                return_to: '/operations/rostering',
            },
            {
                preserveScroll: true,
                onSuccess: () => setRequestReplacementShift(null),
            },
        );
    };

    const reopenShift = (shiftId: number) => {
        if (
            !window.confirm(
                'Reopen this cancelled shift and restore it to planning?',
            )
        )
            return;
        router.patch(
            `/operations/shifts/${shiftId}/reopen`,
            {},
            { preserveScroll: true },
        );
    };

    const reviewLeaveRequest = (
        request: TimeOffRequest,
        action: 'approve' | 'decline',
    ) => {
        if (request.source !== 'hr_leave' || !request.sourceId) {
            router.visit('/hr/leave');
            return;
        }

        router.post(
            `/hr/leave/${request.sourceId}/${action}`,
            { return_to: '/operations/rostering' },
            { preserveScroll: true },
        );
    };

    const tabItems = [
        {
            id: 'shifts',
            label: 'Shifts',
            icon: CalendarDays,
            tone: 'primary' as const,
            badge: total,
        },
        {
            id: 'calendar',
            label: 'Calendar',
            icon: CalendarRange,
            tone: 'info' as const,
        },
        {
            id: 'open',
            label: 'Open shifts',
            icon: AlertTriangle,
            tone: 'warning' as const,
            badge: openCount,
        },
        {
            id: 'coverage',
            label: 'Coverage',
            icon: PieChart,
            tone: 'success' as const,
            badge: `${coverageRate}%`,
        },
        {
            id: 'timeoff',
            label: 'Time off',
            icon: Plane,
            tone: 'info' as const,
            badge: timeOffRequests.length || undefined,
        },
        {
            id: 'availability',
            label: 'Availability',
            icon: CalendarCheck,
            tone: 'success' as const,
            badge: props.staffAvailabilitySummary?.staff.length,
        },
        {
            id: 'capacity',
            label: 'Capacity heatmap',
            icon: LayoutGrid,
            tone: 'violet' as const,
        },
        {
            id: 'analytics',
            label: 'Analytics',
            icon: LineChart,
            tone: 'critical' as const,
        },
        {
            id: 'templates',
            label: 'Templates',
            icon: LayoutTemplate,
            tone: 'violet' as const,
            badge: cachedTemplates?.length,
        },
    ];

    const availabilitySummary = props.staffAvailabilitySummary ?? {
        staff: [],
        upcomingLeave: {},
    };

    const prevLab = weekLabel(addDaysWP(weekStartDate, -7));
    const nextLab = weekLabel(addDaysWP(weekStartDate, 7));
    const curLab = weekLabel(weekStartDate);
    const curCompactRange = `${weekStartDate.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    })} → ${addDaysWP(weekStartDate, 6).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    })}`;

    const heroBadges: Array<{
        label: string;
        tone: 'default' | 'success' | 'warning' | 'critical' | 'info';
        icon?: typeof CalendarDays;
    }> = [];
    if (openCount > 0) {
        heroBadges.push({
            label: `${openCount} open shifts · need cover`,
            tone: 'warning' as const,
            icon: AlertTriangle,
        });
    }
    if (eligibleCounts.blocked > 0) {
        heroBadges.push({
            label: `${eligibleCounts.blocked} blocked candidates`,
            tone: 'critical' as const,
        });
    }
    if (props.rosterPeriod?.status === 'published') {
        heroBadges.push({
            label: `${curLab} published`,
            tone: 'success' as const,
            icon: CheckCircle2,
        });
    } else if (props.rosterPeriod) {
        heroBadges.push({
            label: `${curLab} ${props.rosterPeriod.status.replace(/_/g, ' ')}`,
            tone: 'default' as const,
        });
    }
    if (props.rosteringFeatures.auto_schedule && props.canAutoScheduleRoster) {
        heroBadges.push({
            label: 'Auto-schedule ready',
            tone: 'info' as const,
            icon: Zap,
        });
    }

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Rostering', href: rosteringIndex.url() }]}
        >
            <Head title="Rostering" />
            <div className="space-y-4 p-4">
                <PageHero
                    category="ops"
                    icon={CalendarDays}
                    title={
                        <span>
                            <span className="mb-2 flex items-center gap-2 text-[10.5px] font-semibold tracking-wider text-primary-foreground/80 uppercase">
                                <span
                                    aria-hidden="true"
                                    className="relative inline-flex h-2 w-2"
                                >
                                    <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success ring-2 ring-status-success/30" />
                                </span>
                                Live roster · refreshed just now
                            </span>
                            <span className="block">
                                <span className="font-normal text-primary-foreground/80">
                                    Kia ora {firstName}, your week at a glance —
                                </span>{' '}
                                <span className="border-b-2 border-primary-foreground/40 pb-0.5">
                                    {range.startLabel} → {range.endLabel}
                                </span>
                            </span>
                        </span>
                    }
                    description={
                        <span>
                            {total} shift{total === 1 ? '' : 's'} across{' '}
                            {(props.sites?.length ?? 0) || 'all'} site
                            {(props.sites?.length ?? 0) === 1 ? '' : 's'}.{' '}
                            {openCount > 0
                                ? `${openCount} need${openCount === 1 ? 's' : ''} cover, `
                                : ''}
                            {props.stats.staff_overlaps > 0
                                ? `${props.stats.staff_overlaps} conflict${props.stats.staff_overlaps === 1 ? '' : 's'} to resolve, `
                                : ''}
                            and {props.stats.timesheets_pending} timesheet
                            {props.stats.timesheets_pending === 1
                                ? ''
                                : 's'}{' '}
                            waiting on you.
                        </span>
                    }
                    meta={[
                        {
                            icon: CalendarDays,
                            label: `${curLab} · Mon–Sun`,
                        },
                        {
                            icon: LayoutGrid,
                            label: `${props.sites?.length ?? 0} site${(props.sites?.length ?? 0) === 1 ? '' : 's'}`,
                        },
                        {
                            icon: CheckCircle2,
                            label: `${staffRostered} staff rostered · ${props.analytics?.onLeaveCount ?? 0} on leave`,
                        },
                    ]}
                    badges={heroBadges}
                    stats={[
                        {
                            label: 'Coverage',
                            value: `${coverageRate}%`,
                        },
                        { label: 'Shifts', value: total },
                        { label: 'Staff', value: staffRostered },
                        {
                            label: 'Open',
                            value: openCount,
                        },
                    ]}
                    actions={
                        <>
                            {props.rosteringFeatures.publish &&
                            props.canPublishRoster ? (
                                <Button
                                    size="sm"
                                    className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                    disabled={
                                        !props.rosterPeriod ||
                                        publishBlockCount > 0 ||
                                        props.rosterPeriod.status === 'archived'
                                    }
                                    title={
                                        !props.rosterPeriod
                                            ? 'Pick a site to publish its week'
                                            : publishBlockCount > 0
                                              ? `${publishBlockCount} blocker${publishBlockCount === 1 ? '' : 's'} must be resolved`
                                              : undefined
                                    }
                                    onClick={() =>
                                        postPeriodAction(
                                            canRepublish
                                                ? 'republish'
                                                : 'publish',
                                        )
                                    }
                                    data-test="rostering-confirm-publish"
                                    data-testid="rostering-confirm-publish"
                                >
                                    <CheckCircle2 className="mr-1 h-4 w-4" />
                                    {canRepublish
                                        ? 'Re-publish'
                                        : 'Publish week'}
                                </Button>
                            ) : null}
                            {props.rosteringFeatures.auto_schedule &&
                            props.canAutoScheduleRoster ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
                                    disabled={!props.filters.site_id}
                                    title={
                                        !props.filters.site_id
                                            ? 'Pick a site before generating suggestions'
                                            : undefined
                                    }
                                    onClick={generateSuggestions}
                                    data-test="rostering-suggest-assignments"
                                    data-testid="rostering-suggest-assignments"
                                >
                                    <Wand2 className="mr-1 h-4 w-4" />
                                    Auto-schedule
                                </Button>
                            ) : null}
                            <Link href="/operations/rostering/conflicts">
                                <Button
                                    size="icon"
                                    variant="outline"
                                    aria-label="Conflict queue"
                                    className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
                                >
                                    <MoreHorizontal className="h-4 w-4" />
                                </Button>
                            </Link>
                        </>
                    }
                    footer={
                        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
                            <div className="flex flex-wrap items-center gap-1.5">
                                {/* eslint-disable-next-line no-restricted-syntax -- segmented week-stepper on dark hero; not a shadcn Button. */}
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                                    onClick={() => goWeek(-7)}
                                >
                                    <ChevronLeft className="h-3.5 w-3.5" />
                                    {prevLab}
                                </button>
                                {/* eslint-disable-next-line no-restricted-syntax -- segmented week-stepper on dark hero; not a shadcn Button. */}
                                <button
                                    ref={todayBtnRef}
                                    type="button"
                                    className="inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/35 bg-primary-foreground/20 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/30"
                                    onClick={() => setPickerOpen((v) => !v)}
                                    aria-haspopup="dialog"
                                    aria-expanded={pickerOpen}
                                >
                                    <CalendarRange className="h-3.5 w-3.5" />
                                    {curLab} · {curCompactRange} · pick week
                                    <ChevronDown className="h-3 w-3" />
                                </button>
                                {/* eslint-disable-next-line no-restricted-syntax -- segmented week-stepper on dark hero; not a shadcn Button. */}
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                                    onClick={() => goWeek(7)}
                                >
                                    {nextLab}
                                    <ChevronRight className="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                <EntityFilter
                                    onDark
                                    label="Staff"
                                    pluralLabel="staff"
                                    allLabel="All staff"
                                    items={staffFilterItems}
                                    value={props.filters.staff_id}
                                    onChange={(next) =>
                                        updateFilter({ staff_id: next })
                                    }
                                />
                                <EntityFilter
                                    onDark
                                    label="Client"
                                    allLabel="All clients"
                                    items={clientFilterItems}
                                    value={props.filters.client_id}
                                    onChange={(next) =>
                                        updateFilter({ client_id: next })
                                    }
                                />
                                <SiteFilter
                                    onDark
                                    sites={props.sites ?? []}
                                    value={props.filters.site_ids ?? []}
                                    onChange={(next) =>
                                        updateFilter({ site_ids: next })
                                    }
                                />
                            </div>
                        </div>
                    }
                />

                {/* The summary donuts double as tab switchers (role="tab"), so
                    they need a tablist parent to satisfy WCAG 4.1.2 (axe
                    aria-required-parent). */}
                <div
                    role="tablist"
                    aria-label="Roster summary views"
                    className="grid grid-cols-1 gap-3 lg:grid-cols-3"
                >
                    <DonutCard
                        tone="primary"
                        title="Shifts"
                        subtitle={`${curLab} breakdown`}
                        segments={
                            shiftBreakdown.length > 0
                                ? shiftBreakdown
                                : [
                                      {
                                          key: 'none',
                                          label: 'None',
                                          value: 1,
                                          color: 'var(--muted-foreground)',
                                      },
                                  ]
                        }
                        centerValue={total}
                        centerLabel="shifts"
                        accentKeys={['scheduled', 'in_progress']}
                        active={tab === 'shifts'}
                        cta="View shifts"
                        onClick={() => setTab('shifts')}
                    />
                    <DonutCard
                        tone="warning"
                        title="Open shifts"
                        subtitle="Need cover this week"
                        segments={
                            openBreakdown.length > 0
                                ? openBreakdown
                                : [
                                      {
                                          key: 'none',
                                          label: 'None',
                                          value: 1,
                                          color: 'var(--muted-foreground)',
                                      },
                                  ]
                        }
                        centerValue={openCount}
                        centerLabel={
                            openCount === 1 ? 'shift open' : 'shifts open'
                        }
                        accentKeys={['open', 'blocked']}
                        active={tab === 'open'}
                        cta="View open shifts"
                        onClick={() => setTab('open')}
                    />
                    <DonutCard
                        tone="success"
                        title="Coverage"
                        subtitle="Filled vs. demand"
                        segments={
                            coverageBreakdown.length > 0
                                ? coverageBreakdown
                                : [
                                      {
                                          key: 'none',
                                          label: 'None',
                                          value: 1,
                                          color: 'var(--muted-foreground)',
                                      },
                                  ]
                        }
                        centerValue={`${coverageRate}%`}
                        centerLabel="covered"
                        accentKeys={['covered']}
                        active={tab === 'coverage'}
                        cta="View coverage"
                        onClick={() => setTab('coverage')}
                    />
                </div>

                <TabStrip
                    value={tab}
                    onChange={handleTabChange}
                    items={tabItems}
                />

                <div className="grid gap-4 xl:grid-cols-[1fr_320px]">
                    <main className="min-w-0">
                        {tab === 'shifts' ? (
                            <WeekGridPane
                                days={days}
                                rows={staffRows}
                                siteRows={siteRows}
                                todayKey={todayKey}
                                canManage={props.canManageAny}
                                onUnassign={openUnassignMakeOpenDialog}
                                onCancelShift={(s) => cancelShift(s.id)}
                                onCreateShift={openCreateShift}
                                onAssignOpen={(s) =>
                                    setReassignShift({
                                        id: s.id,
                                        starts_at: s.starts_at,
                                        ends_at: s.ends_at,
                                        client: s.client,
                                        staff: s.staff ?? null,
                                        isOpen: true,
                                    })
                                }
                                onReassign={(s) =>
                                    setReassignShift({
                                        id: s.id,
                                        starts_at: s.starts_at,
                                        ends_at: s.ends_at,
                                        client: s.client,
                                        staff: s.staff ?? null,
                                        isOpen: false,
                                    })
                                }
                                onResolveConflict={(s) =>
                                    setResolveConflictShift(s)
                                }
                                onDuplicateShift={
                                    props.canManageAny
                                        ? (s) => duplicateShift(s.id)
                                        : undefined
                                }
                                onEditShift={
                                    props.canManageAny
                                        ? openEditShift
                                        : undefined
                                }
                                onCopyShiftToDay={
                                    props.canManageAny
                                        ? (s) =>
                                              setCopyToDayShift({
                                                  id: s.id,
                                                  starts_at: s.starts_at,
                                                  client: s.client,
                                              })
                                        : undefined
                                }
                                onMarkEndedEarly={
                                    props.canManageAny
                                        ? (s) =>
                                              setMarkEndedEarlyShift({
                                                  id: s.id,
                                                  starts_at: s.starts_at,
                                                  client: s.client,
                                                  staff: s.staff ?? null,
                                              })
                                        : undefined
                                }
                                onAutoFillShift={
                                    props.canManageAny
                                        ? (s) => autoFillShift(s.id)
                                        : undefined
                                }
                                onReopenCompletedForCorrection={
                                    props.canManageAny
                                        ? (s) =>
                                              setReopenForCorrectionShift({
                                                  id: s.id,
                                                  starts_at: s.starts_at,
                                                  client: s.client,
                                                  staff: s.staff ?? null,
                                              })
                                        : undefined
                                }
                                onPublishShift={
                                    props.canManageAny
                                        ? (s) => publishShift(s.id)
                                        : undefined
                                }
                                onMakeRecurring={
                                    props.canManageAny
                                        ? (s) =>
                                              setMakeRecurringShift({
                                                  id: s.id,
                                                  starts_at: s.starts_at,
                                                  client: s.client,
                                              })
                                        : undefined
                                }
                                onBroadcastShift={
                                    props.canManageAny
                                        ? (s) =>
                                              setBroadcastShift({
                                                  id: s.id,
                                                  starts_at: s.starts_at,
                                                  client: s.client,
                                                  site: null,
                                              })
                                        : undefined
                                }
                                onRequestReplacement={
                                    props.canManageAny
                                        ? (s) =>
                                              setRequestReplacementShift({
                                                  id: s.id,
                                                  starts_at: s.starts_at,
                                                  client: s.client,
                                                  staff: s.staff ?? null,
                                              })
                                        : undefined
                                }
                                onReopenShift={
                                    props.canManageAny
                                        ? (s) => reopenShift(s.id)
                                        : undefined
                                }
                                onReportIncident={(s) =>
                                    router.visit(
                                        `/incidents/create?shift_id=${s.id}`,
                                    )
                                }
                                onViewTimesheet={openTimesheet}
                            />
                        ) : null}
                        {tab === 'calendar' ? (
                            <RosteringCalendarView
                                canManageAny={props.canManageAny}
                                staff={props.staff ?? []}
                                clients={props.clients ?? []}
                                serviceContexts={props.serviceContexts ?? []}
                                defaultServiceContextId={
                                    props.defaultServiceContextId ?? null
                                }
                            />
                        ) : null}
                        {tab === 'open' ? (
                            <OpenShiftsPane
                                stats={openStats}
                                shifts={openShiftCards}
                                canManage={props.canManageAny}
                                replacementRequests={props.replacementQueue}
                                eligibilityAlerts={{
                                    blocked:
                                        props.eligibilityAlerts?.blocked ?? [],
                                    warnings:
                                        props.eligibilityAlerts?.warnings ?? [],
                                }}
                                onAssign={(sh, userId) =>
                                    assignOpenShift(sh.id, userId)
                                }
                                onFindReplacement={(request) =>
                                    router.visit(
                                        `/operations/shifts/${request.shift_id}`,
                                    )
                                }
                            />
                        ) : null}
                        {tab === 'coverage' ? (
                            <CoveragePane
                                stats={coverageStats}
                                windowLabels={coverageWindowLabels}
                                rows={coverageRows}
                                alerts={props.coverageAlerts}
                            />
                        ) : null}
                        {tab === 'timeoff' ? (
                            <TimeOffPane
                                stats={timeOffStats}
                                requests={timeOffRequests}
                                weekStart={weekStartDate}
                                canManage={props.canApproveLeave}
                                onApprove={(request) =>
                                    reviewLeaveRequest(request, 'approve')
                                }
                                onDecline={(request) =>
                                    reviewLeaveRequest(request, 'decline')
                                }
                            />
                        ) : null}
                        {tab === 'availability' ? (
                            props.staffAvailabilitySummary ||
                            !loadingAvailability ? (
                                <AvailabilityPane
                                    staff={availabilitySummary.staff}
                                    upcomingLeave={
                                        availabilitySummary.upcomingLeave
                                    }
                                    canManage={props.canManageAny}
                                />
                            ) : (
                                <Card>
                                    <CardContent className="py-8 text-sm text-muted-foreground">
                                        Loading availability...
                                    </CardContent>
                                </Card>
                            )
                        ) : null}
                        {tab === 'capacity' ? (
                            <CapacityHeatmapPane
                                stats={capacityStats}
                                days={days}
                                rows={capacityRows}
                                todayKey={todayKey}
                            />
                        ) : null}
                        {tab === 'analytics' ? (
                            <AnalyticsPane
                                stats={analyticsStats}
                                coverageTrend={trendPoints}
                                dailyCoverage={
                                    props.analytics?.dailyCoverage ?? []
                                }
                                shiftTypes={shiftTypeSlices}
                                fillBySite={fillBySite}
                                overtimeTrend={overtimeTrend}
                            />
                        ) : null}
                        {tab === 'templates' ? (
                            <TemplatesPane
                                templates={cachedTemplates}
                                loading={loadingTemplates && !cachedTemplates}
                                canManage={Boolean(props.canManageTemplates)}
                                canDelete={Boolean(props.canDeleteTemplates)}
                                onCreate={() =>
                                    setTemplateWizard({
                                        mode: 'create',
                                        template: null,
                                    })
                                }
                                onView={(t) => setDetailTemplate(t)}
                                onEdit={(t) =>
                                    setTemplateWizard({
                                        mode: 'edit',
                                        template: t,
                                    })
                                }
                                onDelete={(t) =>
                                    router.delete(
                                        `/operations/rostering/templates/${t.id}`,
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        ) : null}
                    </main>
                    <SignalRail signals={signals} capacity={capacityRailRows} />
                </div>

                <ResolveConflictDialog
                    open={Boolean(resolveConflictShift)}
                    shift={resolveConflictShift}
                    peers={resolveConflictShift?.conflictPeers ?? []}
                    onOpenChange={(open) => {
                        if (!open) setResolveConflictShift(null);
                    }}
                    onUnassign={(shift) => {
                        setResolveConflictShift(null);
                        openUnassignMakeOpenDialog(shift);
                    }}
                    onReassign={(shift) => {
                        // Resolve the overlap in place: swap the conflict dialog
                        // for the same-page reassign popup instead of navigating.
                        setResolveConflictShift(null);
                        setReassignShift({
                            id: shift.id,
                            starts_at: shift.starts_at,
                            ends_at: shift.ends_at,
                            client: shift.client,
                            staff: shift.staff ?? null,
                            isOpen: false,
                        });
                    }}
                    onOpenQueue={() => {
                        router.visit('/operations/rostering/conflicts');
                    }}
                />

                {editShiftLoadingId ? (
                    <div
                        role="status"
                        className="fixed bottom-6 left-1/2 z-50 inline-flex -translate-x-1/2 items-center gap-2 rounded-lg bg-foreground px-3 py-2 text-xs text-background shadow-lg"
                    >
                        <CalendarDays className="h-4 w-4" />
                        Loading shift editor...
                    </div>
                ) : null}
                {editShiftError ? (
                    <div
                        role="status"
                        className="fixed bottom-6 left-1/2 z-50 inline-flex max-w-sm -translate-x-1/2 items-center gap-2 rounded-lg bg-status-critical px-3 py-2 text-xs text-white shadow-lg"
                    >
                        <AlertTriangle className="h-4 w-4" />
                        {editShiftError}
                    </div>
                ) : null}
                <CreateShiftDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    clients={editDialogClients}
                    staff={props.staff}
                    sites={props.sites}
                    serviceContexts={props.serviceContexts ?? []}
                    defaultServiceContextId={props.defaultServiceContextId ?? null}
                    defaultStartsAt={createDefaults.starts_at ?? null}
                    defaultEndsAt={createDefaults.ends_at ?? null}
                    defaultUserId={createDefaults.user_id ?? null}
                    defaultSiteId={createDefaults.site_id ?? null}
                />

                <CreateShiftDialog
                    key={
                        editShift
                            ? `rostering-edit-${editShift.id}`
                            : 'rostering-edit-none'
                    }
                    open={Boolean(editShift)}
                    onClose={() => setEditShift(null)}
                    clients={editDialogClients}
                    staff={props.staff}
                    sites={props.sites}
                    serviceContexts={props.serviceContexts ?? []}
                    defaultServiceContextId={
                        props.defaultServiceContextId ?? null
                    }
                    initialShift={editShift}
                />

                <CopyToDayDialog
                    open={Boolean(copyToDayShift)}
                    shift={copyToDayShift}
                    onOpenChange={(open) => {
                        if (!open) setCopyToDayShift(null);
                    }}
                    onConfirm={(shift, date) => {
                        copyShiftToDay(shift.id, date);
                        setCopyToDayShift(null);
                    }}
                />

                <MarkEndedEarlyDialog
                    open={Boolean(markEndedEarlyShift)}
                    shift={markEndedEarlyShift}
                    onOpenChange={(open) => {
                        if (!open) setMarkEndedEarlyShift(null);
                    }}
                    onConfirm={(shift, reason) => {
                        markShiftEndedEarly(shift.id, reason);
                        setMarkEndedEarlyShift(null);
                    }}
                />

                <ReopenForCorrectionDialog
                    open={Boolean(reopenForCorrectionShift)}
                    shift={reopenForCorrectionShift}
                    onOpenChange={(open) => {
                        if (!open) setReopenForCorrectionShift(null);
                    }}
                    onConfirm={(shift, reason) => {
                        reopenCompletedShift(shift.id, reason);
                        setReopenForCorrectionShift(null);
                    }}
                />

                <MakeRecurringDialog
                    open={Boolean(makeRecurringShift)}
                    shift={makeRecurringShift}
                    onOpenChange={(open) => {
                        if (!open) setMakeRecurringShift(null);
                    }}
                    onConfirm={(shift, weekdays, endDate) => {
                        promoteShiftToSeries(shift.id, weekdays, endDate);
                        setMakeRecurringShift(null);
                    }}
                />

                <BroadcastDialog
                    open={Boolean(broadcastShift)}
                    shift={broadcastShift}
                    onOpenChange={(open) => {
                        if (!open) setBroadcastShift(null);
                    }}
                    onConfirm={(shift, message) => {
                        broadcastNeedsCover(shift.id, message);
                        setBroadcastShift(null);
                    }}
                />

                <ReassignDialog
                    open={Boolean(reassignShift)}
                    shift={reassignShift}
                    canOverride={Boolean(
                        auth?.can?.shifts?.overrideEligibility ?? true,
                    )}
                    onOpenChange={(open) => {
                        if (!open) setReassignShift(null);
                    }}
                    onAssign={assignShiftToUser}
                />

                <RequestReplacementDialog
                    open={Boolean(requestReplacementShift)}
                    shift={requestReplacementShift}
                    onOpenChange={(open) => {
                        if (!open) setRequestReplacementShift(null);
                    }}
                    onConfirm={(shift, payload) =>
                        requestReplacement(shift.id, payload)
                    }
                />

                <UnassignMakeOpenDialog
                    open={Boolean(unassignMakeOpenShift)}
                    shift={unassignMakeOpenShift}
                    onOpenChange={(open) => {
                        if (!open) setUnassignMakeOpenShift(null);
                    }}
                    onConfirm={(shift, reason) =>
                        unassignShift(shift.id, reason)
                    }
                />

                <ViewTimesheetDialog
                    open={Boolean(viewingTimesheet)}
                    timesheet={viewingTimesheet}
                    canApprove={canApproveTimesheet}
                    onOpenChange={(open) => {
                        if (!open) setViewingTimesheet(null);
                    }}
                />

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
                            <div className="text-sm">
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
                                    data-testid="rostering-review-publish"
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
                                    data-testid="rostering-confirm-publish"
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

                {canViewOperationsReports ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={operationsReportHref}
                            data-test="rostering-operations-report-link"
                            data-testid="rostering-operations-report-link"
                        >
                            <Button size="sm" variant="outline">
                                <BarChart3 className="mr-1 h-4 w-4" />
                                View Operations Reports
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
                    </div>
                ) : null}

                <TemplateWizardDialog
                    open={templateWizard !== null}
                    onOpenChange={(open) => !open && setTemplateWizard(null)}
                    template={templateWizard?.template}
                    clients={props.clients ?? []}
                    staff={props.staff ?? []}
                    serviceContexts={props.serviceContexts ?? []}
                />

                <TemplateDetailDialog
                    template={detailTemplate}
                    open={detailTemplate !== null}
                    onOpenChange={(open) => !open && setDetailTemplate(null)}
                    canManage={Boolean(props.canManageTemplates)}
                    onEdit={(t) => {
                        setDetailTemplate(null);
                        setTemplateWizard({ mode: 'edit', template: t });
                    }}
                />

                {pickerOpen ? (
                    <WeekPicker
                        selectedWeekStart={weekStartDate}
                        anchorRef={todayBtnRef}
                        onSelect={jumpToWeek}
                        onClose={() => setPickerOpen(false)}
                        canPublishWeek={props.canPublishRoster}
                    />
                ) : null}
            </div>
        </AppLayout>
    );
}
