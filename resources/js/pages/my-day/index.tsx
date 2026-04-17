import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Bell,
    Calendar,
    CheckCircle2,
    ChevronRight,
    Clock,
    ClipboardList,
    FileText,
    Home,
    ListTodo,
    Menu,
    OctagonAlert,
    Pill,
    Shield,
    User,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import ClockInCard from '@/components/clock-in-card';
import HandoverReadCard, {
    type HandoverReadPayload,
} from '@/components/handover-read-card';
import RefreshPill from '@/components/refresh-pill';
import type { StaffBottomNavItem } from '@/components/staff-bottom-nav';
import useLiveRefresh from '@/hooks/use-live-refresh';
import StaffPageShell from '@/layouts/staff-page-shell';
import StaffStatus from '@/components/staff-status';
import TimesheetReturnBanner from '@/components/timesheet-return-banner';
import {
    mapIncidentStatus,
    mapMedStatus,
    mapShiftStatus,
    mapTimesheetStatus,
} from '@/lib/status-vocab';

/* -------------------------------------------------------------------------- */
/*  Canonical frontline home — `/my-day`                                      */
/* -------------------------------------------------------------------------- */
/*
 * PR 3 — Single frontline home.
 *
 * This page is the *one* home surface for staff users. It replaces the old
 * `resources/js/pages/my-tasks.tsx` experience; the server now redirects
 * `/my-tasks` → `/my-day` and staff hitting `/dashboard` land here too.
 *
 * Reuses the PR 1 + PR 2 foundation:
 *   - `StaffPageShell` wraps the whole page (header + bottom nav).
 *   - `StaffStatus` renders all worker-facing status chips.
 *   - `status-vocab.ts` collapses backend statuses into the worker vocabulary.
 *
 * Trimmed from the old My Tasks page:
 *   - 6 KPI cards → 3 frontline-relevant summary items
 *     (Shifts today, Meds due, Action needed).
 *   - No breadcrumbs / refresh chrome / raw dashboard framing.
 *   - Header is concise (`StaffHeader`): greeting + date + one primary action.
 *
 * Intentionally NOT in this PR (future PRs own these):
 *   - Clock-in on home
 *   - Guided med round
 *   - Incident wizard
 *   - Full bottom-nav behaviour rewrite
 */

// ---------------------------------------------------------------------------
// Types — mirror the MyTasksController payload (unchanged for this PR)
// ---------------------------------------------------------------------------

interface ShiftClient {
    id: number;
    name: string;
    photo_url: string | null;
}

interface ShiftTaskItem {
    id: number;
    label: string;
    is_completed: boolean;
    completed_at: string | null;
}

interface MyShift {
    id: number;
    starts_at: string;
    ends_at: string;
    actual_starts_at: string | null;
    actual_ends_at: string | null;
    status: string;
    location: string | null;
    service_type: string | null;
    client: ShiftClient;
    tasks: ShiftTaskItem[];
    task_progress: number;
    is_today: boolean;
}

interface MedDue {
    client_name: string;
    medication_name: string;
    dose: string;
    scheduled_for: string;
    status: 'overdue' | 'due' | 'upcoming';
    emar_url: string;
}

interface MyTimesheet {
    id: number;
    work_date: string;
    client_name: string | null;
    hours: number;
    status: string;
    return_notes: string | null;
}

interface MyIncident {
    id: number;
    title: string;
    client_name: string | null;
    severity: string;
    status: string;
    occurred_at: string;
    url: string;
    requires_followup: boolean;
}

interface Task {
    id: string;
    type: 'alert' | 'followup' | 'note_followup';
    title: string;
    priority: 'critical' | 'high' | 'medium' | 'low';
    status: string;
    source_url: string;
    due_at: string | null;
    created_at: string;
    meta: {
        source?: string;
        client_name?: string;
        sla_status?: string;
        asset_name?: string;
    };
}

interface ClockOpenSession {
    id: number;
    clock_in_at: string | null;
    shift_id: number | null;
    client_name: string | null;
    shift_starts_at: string | null;
    shift_ends_at: string | null;
    location: string | null;
    handover_submitted?: boolean;
}

interface ClockActiveShift {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
    location: string | null;
    client_name: string | null;
    incoming_handover?: HandoverReadPayload | null;
}

interface ClockState {
    can_clock: boolean;
    open_session: ClockOpenSession | null;
    active_shift: ClockActiveShift | null;
    eligible_shifts?: ClockActiveShift[];
    eligible_shift_count: number;
}

interface ActiveRound {
    id: number;
    name: string;
    status: 'pending' | 'in_progress' | string;
    scheduled_time: string;
    given: number;
    total: number;
    completed: number;
    percent: number;
    url: string;
}

interface Props {
    today: string;
    shifts: MyShift[];
    medications_due: MedDue[];
    timesheets: MyTimesheet[];
    incidents: MyIncident[];
    tasks: Task[];
    stats: {
        shifts_today: number;
        meds_due: number;
        meds_overdue: number;
        tasks_open: number;
        timesheets_pending: number;
        incidents_open: number;
        cr_alerts: number;
        notifications_unread: number;
    };
    leave: {
        balances: Array<{ type: string; remaining_hours: number; total_hours: number }>;
        pending_requests: number;
    };
    is_manager: boolean;
    manager_data?: {
        team_shifts_today: number;
        unassigned_shifts: number;
        timesheets_pending_approval: number;
        staff_on_today: number;
    };
    clock?: ClockState;
    active_round?: ActiveRound | null;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

type OpenItemFilter = 'all' | 'shift' | 'alert' | 'incident' | 'followup';

const priorityOrder: Record<string, number> = {
    critical: 0,
    high: 1,
    medium: 2,
    low: 3,
};

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-NZ', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}

function formatRelative(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diffMs = now.getTime() - d.getTime();
    const mins = Math.floor(diffMs / 60000);
    const hrs = Math.floor(mins / 60);
    const days = Math.floor(hrs / 24);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    if (hrs < 24) return `${hrs}h ago`;
    if (days < 7) return `${days}d ago`;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

// Map the controller's `MedDue.status` into the worker-facing `med` vocabulary
// via `status-vocab.ts`. Anything not-yet-given from the backend is "Due"; the
// overdue distinction is surfaced separately as a header badge.
function medStatusToWorkerState(s: MedDue['status']): 'due' | 'given' | 'missed' {
    return mapMedStatus(s) ?? 'due';
}

/* -------------------------------------------------------------------------- */
/*  KPI pill — compact frontline summary item                                 */
/* -------------------------------------------------------------------------- */

function HomeKpi({
    label,
    value,
    icon: Icon,
    tone = 'default',
}: {
    label: string;
    value: number | string;
    icon: typeof Calendar;
    tone?: 'default' | 'warn' | 'danger';
}) {
    const ring =
        tone === 'danger'
            ? 'border-red-300 bg-red-50/70 dark:border-red-800/60 dark:bg-red-950/20'
            : tone === 'warn'
                ? 'border-amber-300 bg-amber-50/70 dark:border-amber-800/60 dark:bg-amber-950/20'
                : 'border-border bg-card';
    const iconTone =
        tone === 'danger'
            ? 'text-red-600 dark:text-red-400'
            : tone === 'warn'
                ? 'text-amber-600 dark:text-amber-400'
                : 'text-muted-foreground';
    return (
        <div
            className={`flex items-center gap-3 rounded-lg border px-3 py-2.5 ${ring}`}
        >
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-background/60">
                <Icon className={`h-4 w-4 ${iconTone}`} />
            </div>
            <div className="min-w-0">
                <div className="text-lg font-semibold leading-none">{value}</div>
                <div className="mt-0.5 text-xs text-muted-foreground">{label}</div>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function MyDay({
    today,
    shifts,
    medications_due,
    timesheets,
    incidents,
    tasks,
    stats,
    is_manager,
    manager_data,
    clock,
    active_round,
}: Props) {
    const [openItemFilter, setOpenItemFilter] = useState<OpenItemFilter>('all');

    // PR 6 — replace silent `setInterval(router.reload, 60s)` with a guarded,
    // visible refresh. `useLiveRefresh` suppresses the tick while an input is
    // focused, a modal is open, or the tab is hidden, so content never shifts
    // under an actively-interacting worker. The `RefreshPill` surfaces
    // freshness in the header and lets the worker refresh on demand.
    const { lastUpdatedAt, isRefreshing, refreshNow } = useLiveRefresh();

    const handleTimesheetSubmit = (tsId: number) => {
        // Action endpoint URL is unchanged (see routes/web.php comment).
        router.post(`/my-tasks/timesheet/${tsId}/submit`, {}, { preserveScroll: true });
    };

    // "Action needed" = CR alerts + open incidents + follow-ups + overdue meds.
    // This is the single number a frontline worker should be looking at; it
    // intentionally excludes routine shift/timesheet counts.
    const actionNeededCount =
        stats.cr_alerts +
        stats.incidents_open +
        (tasks?.filter((t) => t.type === 'followup' || t.type === 'note_followup')
            .length ?? 0) +
        stats.meds_overdue;

    // Build the unified Open Items list — reused from the old page, trimmed.
    const openItems: Array<{
        id: string;
        type: 'shift' | 'alert' | 'followup' | 'note_followup' | 'incident';
        title: string;
        priority: string;
        client_name?: string;
        url: string;
        time: string;
        shift_status?: string;
        incident_status?: string;
    }> = [];

    shifts.forEach((s) => {
        openItems.push({
            id: `shift-${s.id}`,
            type: 'shift',
            title: `${s.client.name} — ${formatTime(s.starts_at)} to ${formatTime(s.ends_at)}`,
            priority: s.status === 'in_progress' ? 'high' : 'medium',
            client_name: s.client.name,
            url: `/clients/${s.client.id}`,
            time: s.starts_at,
            shift_status: s.status,
        });
    });

    tasks.forEach((t) => {
        openItems.push({
            id: `task-${t.id}`,
            type: t.type,
            title: t.title,
            priority: t.priority,
            client_name: t.meta.client_name,
            url: t.source_url,
            time: t.created_at,
        });
    });

    incidents.forEach((inc) => {
        openItems.push({
            id: `inc-${inc.id}`,
            type: 'incident',
            title: inc.title,
            priority: inc.severity,
            client_name: inc.client_name ?? undefined,
            url: inc.url,
            time: inc.occurred_at,
            incident_status: inc.status,
        });
    });

    const filteredOpenItems =
        openItemFilter === 'all'
            ? openItems
            : openItemFilter === 'shift'
                ? openItems.filter((i) => i.type === 'shift')
                : openItemFilter === 'incident'
                    ? openItems.filter((i) => i.type === 'incident')
                    : openItemFilter === 'alert'
                        ? openItems.filter((i) => i.type === 'alert')
                        : openItems.filter(
                            (i) => i.type === 'followup' || i.type === 'note_followup',
                        );

    const sortedOpenItems = [...filteredOpenItems].sort((a, b) => {
        const pa = priorityOrder[a.priority] ?? 3;
        const pb = priorityOrder[b.priority] ?? 3;
        if (pa !== pb) return pa - pb;
        return new Date(b.time).getTime() - new Date(a.time).getTime();
    });

    const openItemCounts: Record<OpenItemFilter, number> = {
        all: openItems.length,
        shift: shifts.length,
        alert: openItems.filter((i) => i.type === 'alert').length,
        incident: openItems.filter((i) => i.type === 'incident').length,
        followup: openItems.filter(
            (i) => i.type === 'followup' || i.type === 'note_followup',
        ).length,
    };

    // Sort meds: overdue first, then due, then upcoming.
    const sortedMeds = [...medications_due].sort((a, b) => {
        const statusOrder = { overdue: 0, due: 1, upcoming: 2 };
        const sd = statusOrder[a.status] - statusOrder[b.status];
        if (sd !== 0) return sd;
        return (
            new Date(a.scheduled_for).getTime() - new Date(b.scheduled_for).getTime()
        );
    });

    const greeting = (() => {
        const hr = new Date().getHours();
        if (hr < 12) return 'Good morning';
        if (hr < 18) return 'Good afternoon';
        return 'Good evening';
    })();

    // Bottom nav Clock item reflects the real attendance state:
    //   - clocked in  → "On shift" + pulsing dot, deep-links to clock card
    //   - one eligible shift → "Clock" deep-links to clock card (ready to go)
    //   - multi eligible     → "Pick shift" deep-links to the inline picker
    //   - no context         → "Clock" falls back to /attendance (history + fix)
    // The deep-link hash scroll is smoothed by `scroll-mt` on the card itself.
    const isClockedIn = !!clock?.open_session;
    const hasSingleShift = !!clock?.active_shift;
    const eligibleCount = clock?.eligible_shift_count ?? 0;
    const isAmbiguous = !isClockedIn && !hasSingleShift && eligibleCount > 1;
    const hasClockContext = isClockedIn || hasSingleShift || isAmbiguous;

    const clockLabel = isClockedIn
        ? 'On shift'
        : isAmbiguous
            ? 'Pick shift'
            : 'Clock';

    const clockBadge = isClockedIn ? (
        <span
            aria-hidden
            className="block h-2 w-2 rounded-full bg-emerald-500"
        />
    ) : isAmbiguous ? (
        <span
            aria-hidden
            className="block h-2 w-2 rounded-full bg-amber-500"
        />
    ) : undefined;

    const bottomNavItems = useMemo<StaffBottomNavItem[]>(
        () => [
            { key: 'home', label: 'Home', icon: Home, href: '/my-day' },
            { key: 'meds', label: 'Meds', icon: Pill, href: '/meds/today' },
            {
                key: 'clock',
                label: clockLabel,
                icon: Clock,
                href: hasClockContext ? '/my-day#clock' : '/attendance',
                badge: clockBadge,
            },
            {
                key: 'report',
                label: 'Report',
                icon: ClipboardList,
                href: '/incidents',
            },
            { key: 'more', label: 'More', icon: Menu, href: '/' },
        ],
        [clockLabel, clockBadge, hasClockContext],
    );

    const headerAction = (
        <div className="flex items-center gap-1.5">
            <RefreshPill
                lastUpdatedAt={lastUpdatedAt}
                isRefreshing={isRefreshing}
                onRefresh={refreshNow}
            />
            <Button
                variant="ghost"
                size="sm"
                asChild
                className="relative"
                aria-label={`Notifications (${stats.notifications_unread} unread)`}
            >
                <Link href="/notifications">
                    <Bell className="h-4 w-4" />
                    {stats.notifications_unread > 0 && (
                        <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                            {stats.notifications_unread}
                        </span>
                    )}
                </Link>
            </Button>
        </div>
    );

    return (
        <StaffPageShell
            title={greeting}
            subtitle={today}
            headerAction={headerAction}
            bottomNavItems={bottomNavItems}
        >
            <Head title="My Day" />

            <div className="mx-auto w-full max-w-5xl space-y-5">
                {/* ── Handover read prompt (PR 11) ───────────────────────── */}
                {clock?.active_shift?.incoming_handover && !clock.open_session && (
                    <HandoverReadCard
                        handover={clock.active_shift.incoming_handover}
                    />
                )}

                {/* ── Frontline clock (PR 4) ─────────────────────────────── */}
                {clock && (
                    <ClockInCard
                        canClock={clock.can_clock}
                        openSession={clock.open_session}
                        activeShift={clock.active_shift}
                        eligibleShifts={clock.eligible_shifts}
                        eligibleShiftCount={clock.eligible_shift_count}
                    />
                )}

                {/* ── Trimmed KPI strip (3 items only) ───────────────────── */}
                <div className="grid grid-cols-3 gap-2 sm:gap-3">
                    <HomeKpi
                        label="Shifts today"
                        value={stats.shifts_today}
                        icon={Calendar}
                    />
                    <HomeKpi
                        label="Meds due"
                        value={stats.meds_due}
                        icon={Pill}
                        tone={stats.meds_overdue > 0 ? 'danger' : 'default'}
                    />
                    <HomeKpi
                        label="Action needed"
                        value={actionNeededCount}
                        icon={AlertTriangle}
                        tone={
                            actionNeededCount > 0
                                ? stats.meds_overdue > 0 || stats.cr_alerts > 0
                                    ? 'danger'
                                    : 'warn'
                                : 'default'
                        }
                    />
                </div>

                {/* ── Manager banner (only if a manager lands here directly) ─ */}
                {is_manager && manager_data && (
                    <Card className="border-blue-200 bg-blue-50/60 dark:border-blue-800 dark:bg-blue-950/20">
                        <CardContent className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex flex-wrap items-center gap-x-5 gap-y-1.5 text-sm">
                                <div className="flex items-center gap-2">
                                    <Users className="h-4 w-4 text-blue-600 dark:text-blue-400" />
                                    <span className="font-semibold">
                                        {manager_data.staff_on_today}
                                    </span>
                                    <span className="text-muted-foreground">staff on</span>
                                </div>
                                <div>
                                    <span className="font-semibold">
                                        {manager_data.unassigned_shifts}
                                    </span>{' '}
                                    <span className="text-muted-foreground">unassigned</span>
                                </div>
                                <div>
                                    <span className="font-semibold">
                                        {manager_data.timesheets_pending_approval}
                                    </span>{' '}
                                    <span className="text-muted-foreground">to approve</span>
                                </div>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/operations">
                                    Operations
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {/* ── Active guided round (PR 9) ─────────────────────────── */}
                {active_round && (
                    <Link
                        href={active_round.url}
                        className="group block rounded-xl border border-emerald-300 bg-emerald-50/70 p-4 transition-shadow hover:shadow-sm dark:border-emerald-800/60 dark:bg-emerald-950/20"
                    >
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white">
                                <Pill className="h-5 w-5" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold leading-tight">
                                    {active_round.status === 'in_progress'
                                        ? `Resume ${active_round.name}`
                                        : `Start ${active_round.name}`}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {active_round.completed} of {active_round.total} done
                                    {active_round.scheduled_time
                                        ? ` · ${active_round.scheduled_time.slice(0, 5)}`
                                        : ''}
                                </p>
                                <Progress
                                    value={active_round.percent}
                                    className="mt-2 h-1.5"
                                />
                            </div>
                            <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5" />
                        </div>
                    </Link>
                )}

                {/* ── Medications Due ────────────────────────────────────── */}
                {medications_due.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Pill className="h-4 w-4" />
                                Medications due
                                {stats.meds_overdue > 0 && (
                                    <Badge variant="destructive" className="ml-1">
                                        {stats.meds_overdue} overdue
                                    </Badge>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <ul className="divide-y">
                                {sortedMeds.map((med, idx) => (
                                    <li
                                        key={idx}
                                        className="flex items-center justify-between gap-3 py-2.5"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-medium">
                                                {med.client_name}
                                            </div>
                                            <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                                <Link
                                                    href={med.emar_url}
                                                    className="truncate hover:underline"
                                                >
                                                    {med.medication_name}
                                                </Link>
                                                <span>·</span>
                                                <span className="shrink-0">{med.dose}</span>
                                                <span>·</span>
                                                <span className="shrink-0">
                                                    {formatTime(med.scheduled_for)}
                                                </span>
                                            </div>
                                        </div>
                                        <StaffStatus
                                            kind="med"
                                            state={medStatusToWorkerState(med.status)}
                                            size="sm"
                                        />
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {/* ── My Timesheets ──────────────────────────────────────── */}
                {timesheets.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Clock className="h-4 w-4" />
                                My timesheets
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <ul className="divide-y">
                                {timesheets.map((ts) => {
                                    const tState = mapTimesheetStatus(ts.status);
                                    const needsChanges = tState === 'needs_changes';
                                    return (
                                        <li
                                            key={ts.id}
                                            className="space-y-2 py-2.5 text-sm"
                                        >
                                            <div className="flex items-center justify-between gap-3">
                                                <div className="min-w-0">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-medium">
                                                            {ts.work_date}
                                                        </span>
                                                        {ts.client_name && (
                                                            <span className="truncate text-muted-foreground">
                                                                · {ts.client_name}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="mt-0.5 text-xs text-muted-foreground">
                                                        {ts.hours}h
                                                    </div>
                                                </div>
                                                <div className="flex shrink-0 items-center gap-2">
                                                    {tState && !needsChanges ? (
                                                        <StaffStatus
                                                            kind="timesheet"
                                                            state={tState}
                                                            size="sm"
                                                        />
                                                    ) : null}
                                                    {ts.status === 'draft' && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                handleTimesheetSubmit(ts.id)
                                                            }
                                                        >
                                                            Submit
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                            {needsChanges ? (
                                                <TimesheetReturnBanner
                                                    timesheetId={ts.id}
                                                    returnNote={ts.return_notes}
                                                />
                                            ) : null}
                                        </li>
                                    );
                                })}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {/* ── Open Items ─────────────────────────────────────────── */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <OctagonAlert className="h-4 w-4" />
                            Open items
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 pt-0">
                        {/* Filter tabs */}
                        <div className="-mx-1 flex gap-1 overflow-x-auto rounded-lg border bg-muted/50 p-1">
                            {(
                                [
                                    { key: 'all', label: 'All' },
                                    { key: 'shift', label: 'Shifts' },
                                    { key: 'alert', label: 'Alerts' },
                                    { key: 'incident', label: 'Incidents' },
                                    { key: 'followup', label: 'Follow-ups' },
                                ] as const
                            ).map((tab) => (
                                <button
                                    key={tab.key}
                                    onClick={() => setOpenItemFilter(tab.key)}
                                    className={`flex shrink-0 items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                        openItemFilter === tab.key
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {tab.label}
                                    <span
                                        className={`ml-0.5 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full px-1.5 text-xs font-semibold ${
                                            openItemFilter === tab.key
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted-foreground/15 text-muted-foreground'
                                        }`}
                                    >
                                        {openItemCounts[tab.key]}
                                    </span>
                                </button>
                            ))}
                        </div>

                        {sortedOpenItems.length === 0 ? (
                            <div className="flex flex-col items-center py-8 text-center">
                                <CheckCircle2 className="h-8 w-8 text-green-500" />
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Nothing open here right now.
                                </p>
                            </div>
                        ) : (
                            <ul className="space-y-2">
                                {sortedOpenItems.map((item) => {
                                    const TypeIcon =
                                        item.type === 'shift'
                                            ? Calendar
                                            : item.type === 'incident'
                                                ? AlertTriangle
                                                : item.type === 'followup' ||
                                                    item.type === 'note_followup'
                                                    ? ClipboardList
                                                    : item.type === 'alert'
                                                        ? Bell
                                                        : FileText;

                                    return (
                                        <li key={item.id}>
                                            <Link
                                                href={item.url}
                                                className="group flex items-center gap-3 rounded-lg border bg-card px-3 py-2.5 transition-all hover:border-primary/30 hover:shadow-sm"
                                            >
                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                                    <TypeIcon className="h-4 w-4" />
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="truncate text-sm font-medium group-hover:text-primary">
                                                            {item.title}
                                                        </span>
                                                        {item.type === 'shift' &&
                                                            item.shift_status && (() => {
                                                                const s = mapShiftStatus(
                                                                    item.shift_status,
                                                                );
                                                                return s ? (
                                                                    <StaffStatus
                                                                        kind="shift"
                                                                        state={s}
                                                                        size="sm"
                                                                    />
                                                                ) : null;
                                                            })()}
                                                        {item.type === 'incident' && (() => {
                                                            const s = mapIncidentStatus(
                                                                item.incident_status ?? 'submitted',
                                                            );
                                                            return s ? (
                                                                <StaffStatus
                                                                    kind="incident"
                                                                    state={s}
                                                                    size="sm"
                                                                />
                                                            ) : null;
                                                        })()}
                                                    </div>
                                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                                        {item.client_name && (
                                                            <span className="flex items-center gap-1">
                                                                <User className="h-3 w-3" />
                                                                {item.client_name}
                                                            </span>
                                                        )}
                                                        <span className="flex items-center gap-1">
                                                            <Clock className="h-3 w-3" />
                                                            {formatRelative(item.time)}
                                                        </span>
                                                    </div>
                                                </div>
                                                <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5" />
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                {/* ── Footer quick links ─────────────────────────────────── */}
                <div className="flex flex-wrap gap-2 pt-1">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/control-room">
                            <Shield className="mr-2 h-4 w-4" />
                            Control room
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/control-room/alerts">
                            <ListTodo className="mr-2 h-4 w-4" />
                            All alerts
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/meds/today">
                            <Pill className="mr-2 h-4 w-4" />
                            Meds today
                        </Link>
                    </Button>
                </div>
            </div>

        </StaffPageShell>
    );
}
